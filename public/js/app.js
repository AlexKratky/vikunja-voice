const API = '/api/recordings';
const $ = (id) => document.getElementById(id);

// ---------- token ----------
let token = localStorage.getItem('vv_token') || '';

function showSections() {
    $('token-section').hidden = !!token;
    $('recorder-section').hidden = !token;
    $('history-section').hidden = !token;
}

$('token-form').addEventListener('submit', (e) => {
    e.preventDefault();
    token = $('token-input').value.trim();
    localStorage.setItem('vv_token', token);
    showSections();
});

// ---------- IndexedDB history ----------
function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open('vikunja-voice', 1);
        req.onupgradeneeded = () => {
            req.result.createObjectStore('recordings', { keyPath: 'id', autoIncrement: true });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function dbAdd(entry) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('recordings', 'readwrite');
        const req = tx.objectStore('recordings').add(entry);
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function dbUpdate(id, patch) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const store = db.transaction('recordings', 'readwrite').objectStore('recordings');
        const req = store.get(id);
        req.onsuccess = () => {
            const updated = Object.assign(req.result, patch);
            const put = store.put(updated);
            put.onsuccess = () => resolve(updated);
            put.onerror = () => reject(put.error);
        };
        req.onerror = () => reject(req.error);
    });
}

async function dbAll() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const req = db.transaction('recordings', 'readonly').objectStore('recordings').getAll();
        req.onsuccess = () => resolve(req.result.reverse());
        req.onerror = () => reject(req.error);
    });
}

// ---------- recording ----------
let mediaRecorder = null;
let current = null; // { blob, mime }

function pickMime() {
    for (const m of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']) {
        if (window.MediaRecorder && MediaRecorder.isTypeSupported(m)) return m;
    }
    return '';
}

function extFor(mime) {
    if (mime.includes('webm')) return 'webm';
    if (mime.includes('mp4')) return 'm4a';
    if (mime.includes('ogg')) return 'ogg';
    return 'bin';
}

$('record').addEventListener('click', async () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        // Disable immediately: MediaRecorder.state flips to 'inactive'
        // synchronously inside stop(), before onstop fires. Disabling the
        // button closes that window so a rapid second tap can't fall
        // through to the "start a new recording" branch below and race
        // with this recording's still-pending onstop.
        $('record').disabled = true;
        mediaRecorder.stop();
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const mime = pickMime();
        // Keep the recorder instance and its chunk buffer in local scope so
        // the ondataavailable/onstop closures are self-contained and can't
        // be corrupted if the shared `mediaRecorder` binding is ever
        // reassigned before this recording's onstop has run.
        const recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        const recChunks = [];
        mediaRecorder = recorder;

        recorder.ondataavailable = (e) => recChunks.push(e.data);
        recorder.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            const type = (recorder.mimeType || 'audio/webm').split(';')[0];
            current = { blob: new Blob(recChunks, { type }), mime: type };
            $('preview-audio').src = URL.createObjectURL(current.blob);
            $('preview').hidden = false;
            $('record').classList.remove('recording');
            $('record').disabled = false;
            $('status').textContent = 'Zkontroluj a odešli';
        };

        recorder.start();
        $('record').classList.add('recording');
        $('preview').hidden = true;
        $('status').textContent = 'Nahrávám… (klepni pro stop)';
    } catch (err) {
        $('status').textContent = 'Mikrofon nedostupný: ' + err.message;
    }
});

$('discard').addEventListener('click', () => {
    current = null;
    $('preview').hidden = true;
    $('status').textContent = 'Připraveno';
});

// ---------- upload ----------
async function upload(blob, mime) {
    const form = new FormData();
    form.append('audio', blob, 'recording.' + extFor(mime));

    const res = await fetch(API, {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token },
        body: form,
    });

    if (res.status === 401) {
        localStorage.removeItem('vv_token');
        token = '';
        showSections();
        throw new Error('Neplatný token');
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
}

$('send').addEventListener('click', async () => {
    if (!current) return;
    const { blob, mime } = current;

    $('preview').hidden = true;
    current = null;
    $('status').textContent = 'Odesílám…';

    let id = null;
    try {
        id = await dbAdd({ blob, mime, createdAt: Date.now(), status: 'sending' });
    } catch (_) {
        id = null;
    }

    try {
        await upload(blob, mime);
        if (id !== null) await dbUpdate(id, { status: 'sent' });
        $('status').textContent = 'Odesláno ✓ (zpracuje se na pozadí)';
    } catch (err) {
        if (id !== null) await dbUpdate(id, { status: 'error' });
        $('status').textContent = 'Chyba odeslání: ' + err.message + ' — v historii jde poslat znovu';
    }

    renderHistory();
});

// ---------- history ----------
const SVG_PLAY = '<svg class="icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.14v13.72L19 12z"/></svg>';
const SVG_STOP = '<svg class="icon-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6.5" y="6.5" width="11" height="11" rx="2"/></svg>';
const SVG_RETRY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';

// One shared player for history entries so plays never overlap and
// object URLs get revoked when playback stops.
let historyAudio = null;
let historyPlayBtn = null;

function stopHistoryPlayback() {
    if (historyAudio) {
        historyAudio.pause();
        URL.revokeObjectURL(historyAudio.src);
        historyAudio = null;
    }
    if (historyPlayBtn) {
        historyPlayBtn.classList.remove('playing');
        historyPlayBtn.setAttribute('aria-label', 'Přehrát nahrávku');
        historyPlayBtn = null;
    }
}

async function renderHistory() {
    const list = $('history');
    stopHistoryPlayback();
    list.innerHTML = '';
    const entries = await dbAll();

    if (entries.length === 0) {
        const li = document.createElement('li');
        li.className = 'empty';
        li.textContent = 'Zatím žádné nahrávky';
        list.appendChild(li);
        return;
    }

    for (const entry of entries) {
        const li = document.createElement('li');
        li.className = 'entry';

        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'icon-btn';
        play.setAttribute('aria-label', 'Přehrát nahrávku');
        play.innerHTML = SVG_PLAY + SVG_STOP;
        play.addEventListener('click', () => {
            if (historyPlayBtn === play) {
                stopHistoryPlayback();
                return;
            }
            stopHistoryPlayback();
            historyAudio = new Audio(URL.createObjectURL(entry.blob));
            historyAudio.addEventListener('ended', stopHistoryPlayback);
            historyPlayBtn = play;
            play.classList.add('playing');
            play.setAttribute('aria-label', 'Zastavit přehrávání');
            historyAudio.play();
        });
        li.appendChild(play);

        const meta = document.createElement('div');
        meta.className = 'entry-meta';

        const when = new Date(entry.createdAt).toLocaleString('cs-CZ', {
            day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit',
        });
        const time = document.createElement('span');
        time.className = 'entry-time';
        time.textContent = when;
        meta.appendChild(time);

        const badge = document.createElement('span');
        badge.className = entry.status === 'sent' ? 'badge ok' : 'badge err';
        badge.textContent = entry.status === 'sent' ? 'odesláno' : 'chyba';
        meta.appendChild(badge);

        li.appendChild(meta);

        if (entry.status !== 'sent') {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-ghost btn-retry';
            retry.innerHTML = SVG_RETRY + '<span>Znovu</span>';
            retry.addEventListener('click', async () => {
                retry.disabled = true;
                try {
                    await upload(entry.blob, entry.mime);
                    await dbUpdate(entry.id, { status: 'sent' });
                } catch (_) {
                    retry.disabled = false;
                }
                renderHistory();
            });
            li.appendChild(retry);
        }

        list.appendChild(li);
    }
}

// ---------- presentation: status pill & record button label ----------
// Maps the exact status strings set above onto a data-state attribute so
// CSS can color the status dot, and keeps the record button's aria-label
// in sync with what a click on it will do.
(() => {
    const statusEl = $('status');
    const record = $('record');

    const stateFor = (text) => {
        if (text.startsWith('Nahrávám')) return 'recording';
        if (text.startsWith('Odesílám')) return 'sending';
        if (text.startsWith('Odesláno')) return 'sent';
        if (text.startsWith('Zkontroluj')) return 'review';
        if (text.startsWith('Chyba') || text.startsWith('Mikrofon')) return 'error';
        return 'ready';
    };

    const sync = () => {
        const state = stateFor(statusEl.textContent.trim());
        statusEl.dataset.state = state;
        record.setAttribute('aria-label', state === 'recording' ? 'Zastavit nahrávání' : 'Nahrávat');
    };

    new MutationObserver(sync).observe(statusEl, { childList: true, characterData: true, subtree: true });
    sync();
})();

// ---------- presentation: preview player ----------
// Minimal custom player over the (controls-less) #preview-audio element.
(() => {
    const audio = $('preview-audio');
    const toggle = $('player-toggle');
    const seek = $('player-seek');
    const time = $('player-time');
    let duration = 0;

    const fmt = (s) => {
        if (!Number.isFinite(s) || s < 0) s = 0;
        return Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');
    };

    const setPlaying = (playing) => {
        toggle.classList.toggle('playing', playing);
        toggle.setAttribute('aria-label', playing ? 'Pozastavit' : 'Přehrát');
    };

    const render = () => {
        const pct = duration > 0 ? Math.min(100, (audio.currentTime / duration) * 100) : 0;
        seek.value = String(pct);
        seek.style.setProperty('--fill', pct + '%');
        time.textContent = fmt(duration > 0 ? audio.currentTime : 0) + ' / ' + fmt(duration);
    };

    audio.addEventListener('loadedmetadata', () => {
        setPlaying(false);
        if (Number.isFinite(audio.duration)) {
            duration = audio.duration;
            render();
        } else {
            // Chrome reports Infinity for fresh MediaRecorder blobs; seeking
            // far past the end forces the real duration to be computed.
            duration = 0;
            render();
            const probe = () => {
                if (Number.isFinite(audio.duration) && audio.duration > 0) {
                    audio.removeEventListener('durationchange', probe);
                    duration = audio.duration;
                    audio.currentTime = 0;
                    render();
                }
            };
            audio.addEventListener('durationchange', probe);
            audio.currentTime = 1e7;
        }
    });

    audio.addEventListener('timeupdate', () => { if (duration > 0) render(); });
    audio.addEventListener('play', () => setPlaying(true));
    audio.addEventListener('pause', () => setPlaying(false));
    audio.addEventListener('ended', () => {
        audio.currentTime = 0;
        render();
    });

    toggle.addEventListener('click', () => {
        if (audio.paused) audio.play(); else audio.pause();
    });

    seek.addEventListener('input', () => {
        if (duration > 0) {
            audio.currentTime = (Number(seek.value) / 100) * duration;
            render();
        } else {
            seek.value = '0';
        }
    });

    // Stop playback when the preview is dismissed (send/discard).
    new MutationObserver(() => {
        if ($('preview').hidden && !audio.paused) audio.pause();
    }).observe($('preview'), { attributes: true, attributeFilter: ['hidden'] });
})();

// ---------- init ----------
showSections();
renderHistory();

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
