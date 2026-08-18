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
    if (mime.includes('mp4') || mime.includes('m4a')) return 'm4a';
    if (mime.includes('ogg')) return 'ogg';
    if (mime.includes('mpeg') || mime.includes('mp3')) return 'mp3';
    if (mime.includes('wav')) return 'wav';
    return 'bin';
}

// ---------- live recording timer + waveform ----------
const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const WAVE_BAR_W = 3;      // css px
const WAVE_BAR_GAP = 2;    // css px
const WAVE_SAMPLE_MS = 50; // one new bar per 50ms

const recLive = { raf: 0, audioCtx: null, analyser: null, startedAt: 0, lastBarAt: 0, bars: [], waveColor: '#e5484d' };

function fmtRecTimer(ms) {
    const total = Math.floor(ms / 1000);
    const base = Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
    // Reduced motion: whole seconds only, no waveform.
    if (REDUCED_MOTION) return base;
    return base + '.' + Math.floor((ms % 1000) / 100);
}

function startRecLive(stream) {
    recLive.startedAt = performance.now();
    recLive.lastBarAt = 0;
    recLive.bars = [];
    $('rec-timer').textContent = REDUCED_MOTION ? '0:00' : '0:00.0';
    $('rec-live').hidden = false;

    const canvas = $('rec-wave');
    canvas.hidden = REDUCED_MOTION;
    if (!REDUCED_MOTION) {
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            recLive.audioCtx = new AC();
            recLive.analyser = recLive.audioCtx.createAnalyser();
            recLive.analyser.fftSize = 256;
            recLive.audioCtx.createMediaStreamSource(stream).connect(recLive.analyser);
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width = Math.round(rect.width * dpr);
            canvas.height = Math.round(rect.height * dpr);
            recLive.waveColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#e5484d';
        } catch (_) {
            // Waveform is progressive enhancement; the timer works without it.
            recLive.analyser = null;
            canvas.hidden = true;
        }
    }

    const tick = () => {
        $('rec-timer').textContent = fmtRecTimer(performance.now() - recLive.startedAt);
        if (recLive.analyser) drawRecWave();
        recLive.raf = requestAnimationFrame(tick);
    };
    recLive.raf = requestAnimationFrame(tick);
}

function stopRecLive() {
    cancelAnimationFrame(recLive.raf);
    recLive.raf = 0;
    if (recLive.audioCtx) {
        recLive.audioCtx.close();
        recLive.audioCtx = null;
    }
    recLive.analyser = null;
    recLive.bars = [];
    $('rec-live').hidden = true;
}

function drawRecWave() {
    const canvas = $('rec-wave');
    const g = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const step = (WAVE_BAR_W + WAVE_BAR_GAP) * dpr;
    const now = performance.now();

    if (now - recLive.lastBarAt >= WAVE_SAMPLE_MS) {
        recLive.lastBarAt = now;
        const data = new Uint8Array(recLive.analyser.fftSize);
        recLive.analyser.getByteTimeDomainData(data);
        let peak = 0;
        for (const v of data) {
            const amp = Math.abs(v - 128) / 128;
            if (amp > peak) peak = amp;
        }
        recLive.bars.push(peak);
        const cap = Math.ceil(canvas.width / step);
        if (recLive.bars.length > cap) recLive.bars.splice(0, recLive.bars.length - cap);
    }

    g.clearRect(0, 0, canvas.width, canvas.height);
    g.fillStyle = recLive.waveColor;
    const mid = canvas.height / 2;
    for (let i = 0; i < recLive.bars.length; i++) {
        const h = Math.max(2 * dpr, recLive.bars[recLive.bars.length - 1 - i] * canvas.height * 0.9);
        const x = canvas.width - (i + 1) * step;
        if (x + WAVE_BAR_W * dpr < 0) break;
        if (g.roundRect) {
            g.beginPath();
            g.roundRect(x, mid - h / 2, WAVE_BAR_W * dpr, h, (WAVE_BAR_W * dpr) / 2);
            g.fill();
        } else {
            g.fillRect(x, mid - h / 2, WAVE_BAR_W * dpr, h);
        }
    }
}

// MIME types the backend RecordingController accepts. Keep in sync.
const ACCEPTED_MIMES = {
    'audio/webm': true,
    'video/webm': true,
    'audio/ogg': true,
    'audio/mp4': true,
    'audio/x-m4a': true,
    'video/mp4': true,
    'audio/mpeg': true,
    'audio/wav': true,
    'audio/x-wav': true,
};

const EXT_TO_MIME = {
    webm: 'audio/webm',
    ogg: 'audio/ogg',
    m4a: 'audio/mp4',
    mp4: 'audio/mp4',
    mp3: 'audio/mpeg',
    wav: 'audio/wav',
};

const MAX_IMPORT_BYTES = 20 * 1024 * 1024;

function mimeFromFile(file) {
    const type = (file.type || '').split(';')[0].trim().toLowerCase();
    if (type && ACCEPTED_MIMES[type]) return type;
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    return EXT_TO_MIME[ext] || '';
}

function showPreview(blob, mime) {
    current = { blob, mime };
    const audio = $('preview-audio');
    const prev = audio.src;
    audio.src = URL.createObjectURL(blob);
    if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev);
    $('preview').hidden = false;
    setStatus('review', STR.review);
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
            stopRecLive();
            stream.getTracks().forEach((t) => t.stop());
            const type = (recorder.mimeType || 'audio/webm').split(';')[0];
            showPreview(new Blob(recChunks, { type }), type);
            $('record').classList.remove('recording');
            $('record').disabled = false;
            $('import').disabled = false;
        };

        recorder.start();
        startRecLive(stream);
        $('record').classList.add('recording');
        $('preview').hidden = true;
        $('import').disabled = true;
        setStatus('recording', STR.recording);
    } catch (err) {
        setStatus('error', STR.mic_error + ': ' + err.message);
    }
});

$('import').addEventListener('click', () => {
    if (mediaRecorder && mediaRecorder.state === 'recording') return;
    $('import-audio').click();
});

$('import-audio').addEventListener('change', () => {
    const input = $('import-audio');
    const file = input.files && input.files[0];
    input.value = '';
    if (!file) return;

    const mime = mimeFromFile(file);
    if (!mime || file.size === 0 || file.size > MAX_IMPORT_BYTES) {
        setStatus('error', STR.import_error);
        return;
    }

    showPreview(file, mime);
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
const SVG_SHARE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';

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

function shareFilename(entry) {
    const pad2 = (n) => String(n).padStart(2, '0');
    const d = new Date(entry.createdAt);
    return 'vikunja-voice-' + d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
        + '-' + pad2(d.getHours()) + pad2(d.getMinutes()) + '.' + extFor(entry.mime);
}

function downloadEntry(entry) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(entry.blob);
    a.download = shareFilename(entry);
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Revoke later, not immediately: the download triggered by click()
    // reads the object URL asynchronously.
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}

async function shareEntry(entry) {
    const file = new File([entry.blob], shareFilename(entry), { type: entry.mime });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
        try {
            await navigator.share({ files: [file] });
        } catch (err) {
            // AbortError = user closed the share sheet; anything else
            // (share target crashed, permission issue) falls back to download.
            if (err.name !== 'AbortError') downloadEntry(entry);
        }
        return;
    }
    downloadEntry(entry);
}

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

        const actions = document.createElement('div');
        actions.className = 'entry-actions';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-ghost btn-retry';
        retry.innerHTML = SVG_RETRY + '<span></span>';
        retry.querySelector('span').textContent = STR.retry;
        retry.addEventListener('click', async () => {
            if (entry.status === 'sent' && !confirm(STR.confirm_resend_duplicate)) return;
            retry.disabled = true;
            setStatus('sending', STR.sending);
            try {
                await upload(entry.blob, entry.mime);
                await dbUpdate(entry.id, { status: 'sent' });
                setStatus('sent', STR.resent);
            } catch (err) {
                retry.disabled = false;
                setStatus('error', STR.send_error + ': ' + err.message);
            }
            renderHistory();
        });
        actions.appendChild(retry);

        const share = document.createElement('button');
        share.type = 'button';
        share.className = 'icon-btn icon-btn-sm';
        share.setAttribute('aria-label', STR.share_recording);
        share.innerHTML = SVG_SHARE;
        share.addEventListener('click', () => shareEntry(entry));
        actions.appendChild(share);

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
        actions.appendChild(del);

        li.appendChild(actions);
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
