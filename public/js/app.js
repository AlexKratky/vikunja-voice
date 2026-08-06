const API = '/api/recordings';
const $ = (id) => document.getElementById(id);

// Locale + UI strings injected by the blade template (window.VV). The guard
// only prevents a hard crash if a stale cached page without VV loads this file.
const VV = window.VV || { locale: 'en', strings: {} };
const STR = VV.strings;

// Single place that changes the status line: text, the data-state attribute
// CSS colors the dot by, and the record button's aria-label (what a click on
// it will do) always move together.
function setStatus(state, text) {
    const status = $('status');
    status.textContent = text;
    status.dataset.state = state;
    $('record').setAttribute('aria-label', state === 'recording' ? STR.stop_recording : STR.record);
}

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

async function dbDelete(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const req = db.transaction('recordings', 'readwrite').objectStore('recordings').delete(id);
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
}

async function dbClear() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const req = db.transaction('recordings', 'readwrite').objectStore('recordings').clear();
        req.onsuccess = () => resolve();
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
            setStatus('review', STR.review);
        };

        recorder.start();
        $('record').classList.add('recording');
        $('preview').hidden = true;
        setStatus('recording', STR.recording);
    } catch (err) {
        setStatus('error', STR.mic_error + ': ' + err.message);
    }
});

$('discard').addEventListener('click', () => {
    current = null;
    $('preview').hidden = true;
    setStatus('ready', STR.ready);
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
        throw new Error(STR.invalid_token);
    }
    if (!res.ok) throw new Error('HTTP ' + res.status);
}

$('send').addEventListener('click', async () => {
    if (!current) return;
    const { blob, mime } = current;

    $('preview').hidden = true;
    current = null;
    setStatus('sending', STR.sending);

    let id = null;
    try {
        id = await dbAdd({ blob, mime, createdAt: Date.now(), status: 'sending' });
    } catch (_) {
        id = null;
    }

    try {
        await upload(blob, mime);
        if (id !== null) await dbUpdate(id, { status: 'sent' });
        setStatus('sent', STR.sent);
    } catch (err) {
        if (id !== null) await dbUpdate(id, { status: 'error' });
        setStatus('error', STR.send_error + ': ' + err.message + ' — ' + STR.send_error_hint);
    }

    renderHistory();
});

// ---------- history ----------
const SVG_PLAY = '<svg class="icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.14v13.72L19 12z"/></svg>';
const SVG_STOP = '<svg class="icon-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6.5" y="6.5" width="11" height="11" rx="2"/></svg>';
const SVG_RETRY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
const SVG_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

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
        historyPlayBtn.setAttribute('aria-label', STR.play_recording);
        historyPlayBtn = null;
    }
}

$('clear-history').addEventListener('click', async () => {
    if (!confirm(STR.confirm_delete_all)) return;
    await dbClear();
    renderHistory();
});

async function renderHistory() {
    const list = $('history');
    stopHistoryPlayback();
    list.innerHTML = '';
    const entries = await dbAll();

    $('clear-history').hidden = entries.length === 0;

    if (entries.length === 0) {
        const li = document.createElement('li');
        li.className = 'empty';
        li.textContent = STR.empty_history;
        list.appendChild(li);
        return;
    }

    for (const entry of entries) {
        const li = document.createElement('li');
        li.className = 'entry';

        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'icon-btn';
        play.setAttribute('aria-label', STR.play_recording);
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
            play.setAttribute('aria-label', STR.stop_playback);
            historyAudio.play();
        });
        li.appendChild(play);

        const meta = document.createElement('div');
        meta.className = 'entry-meta';

        const when = new Date(entry.createdAt).toLocaleString(VV.locale, {
            day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit',
        });
        const time = document.createElement('span');
        time.className = 'entry-time';
        time.textContent = when;
        meta.appendChild(time);

        const badge = document.createElement('span');
        badge.className = entry.status === 'sent' ? 'badge ok' : 'badge err';
        badge.textContent = entry.status === 'sent' ? STR.badge_sent : STR.badge_error;
        meta.appendChild(badge);

        li.appendChild(meta);

        if (entry.status !== 'sent') {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-ghost btn-retry';
            retry.innerHTML = SVG_RETRY + '<span></span>';
            retry.querySelector('span').textContent = STR.retry;
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

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'icon-btn icon-btn-sm icon-btn-danger';
        del.setAttribute('aria-label', STR.delete_recording);
        del.innerHTML = SVG_TRASH;
        del.addEventListener('click', async () => {
            if (!confirm(STR.confirm_delete)) return;
            await dbDelete(entry.id);
            renderHistory();
        });
        li.appendChild(del);

        list.appendChild(li);
    }
}

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
        toggle.setAttribute('aria-label', playing ? STR.pause : STR.play);
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
