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
    $('record').setAttribute('aria-label', state === 'recording' ? STR.stop_recording
        : current && current.take ? STR.continue_recording : STR.record);
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
    if (pendingShare) {
        importFile(pendingShare);
        pendingShare = null;
    }
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
let current = null;   // preview entry: { blob, mime, createdAt, id, persist, take }
let recording = null; // the take whose mic is live right now

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

const recLive = { raf: 0, analyser: null, startedAt: 0, lastBarAt: 0, bars: [], waveColor: '#e5484d' };

function fmtRecTimer(ms) {
    const total = Math.floor(ms / 1000);
    const base = Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
    // Reduced motion: whole seconds only, no waveform.
    if (REDUCED_MOTION) return base;
    return base + '.' + Math.floor((ms % 1000) / 100);
}

// elapsedMs is what the take already holds, so a continued take's timer
// picks up where it stopped. analyser may be null: the waveform is
// progressive enhancement, the timer works without it.
function startRecLive(analyser, elapsedMs) {
    recLive.startedAt = performance.now() - elapsedMs;
    recLive.lastBarAt = 0;
    recLive.bars = [];
    $('rec-timer').textContent = fmtRecTimer(elapsedMs);
    $('rec-live').hidden = false;

    const canvas = $('rec-wave');
    recLive.analyser = REDUCED_MOTION ? null : analyser;
    canvas.hidden = !recLive.analyser;
    if (recLive.analyser) {
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);
        recLive.waveColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#e5484d';
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

// ---------- microphone picker ----------
// Shown only when there is more than one input to choose from. Browsers
// blank device labels until mic permission is granted, so on a first visit
// the picker appears after the first recording. Chrome adds virtual
// 'default' / 'communications' entries: 'default' is kept as an option
// (its label names the OS default), neither counts as an extra input.
let micsShown = false;

async function refreshMics() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    let inputs;
    try {
        inputs = (await navigator.mediaDevices.enumerateDevices())
            .filter((d) => d.kind === 'audioinput' && d.deviceId && d.deviceId !== 'communications');
    } catch (_) {
        return;
    }
    const physical = inputs.filter((d) => d.deviceId !== 'default');
    micsShown = physical.length > 1 && inputs.every((d) => d.label);

    const select = $('mic-select');
    select.innerHTML = '';
    if (micsShown) {
        for (const d of inputs) select.add(new Option(d.label, d.deviceId));
        const saved = localStorage.getItem('vv_mic');
        if (inputs.some((d) => d.deviceId === saved)) select.value = saved;
    }
    $('mic-row').hidden = !micsShown;
}

// While recording, the picker gives way to the label of the track actually
// being captured, so it's clear which mic is live. null restores the picker.
function setMicLive(track) {
    $('mic-select').hidden = !!track;
    $('mic-live').hidden = !track;
    $('mic-live').textContent = track ? track.label : '';
}

$('mic-select').addEventListener('change', () => {
    localStorage.setItem('vv_mic', $('mic-select').value);
});

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
    opus: 'audio/ogg',
    m4a: 'audio/mp4',
    mp4: 'audio/mp4',
    mp3: 'audio/mpeg',
    wav: 'audio/wav',
};

const MAX_IMPORT_BYTES = 20 * 1024 * 1024;

function mimeFromFile(file) {
    let type = (file.type || '').split(';')[0].trim().toLowerCase();
    // Opus in an Ogg container: the backend sniffs and accepts it as audio/ogg.
    if (type === 'audio/opus') type = 'audio/ogg';
    if (type && ACCEPTED_MIMES[type]) return type;
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    return EXT_TO_MIME[ext] || '';
}

// take: the paused take this blob came from, when it can still be continued.
function showPreview(blob, mime, take = null) {
    if (take && current && current.take === take) {
        // The same take stopped again: its row grows instead of a new one appearing.
        current.blob = blob;
        current.persist = current.persist.then((id) => (id === null ? null
            : dbUpdate(id, { blob }).then(() => { renderHistory(); return id; }, () => id)));
    } else {
        clearCurrent(); // e.g. an import replacing a take paused in review
        const createdAt = Date.now();
        const entry = { blob, mime, createdAt, id: null, persist: null, take };
        // Persist as 'unsent' right away so the recording survives the app being
        // closed or killed before Send is tapped — it must never just disappear.
        // entry.persist lets the send/discard handlers wait for the row to exist
        // (a fast tap would otherwise race dbAdd). dbAdd gets a plain literal, not
        // `entry`: a Promise isn't structured-cloneable.
        entry.persist = dbAdd({ blob, mime, createdAt, status: 'unsent' })
            .then((id) => { entry.id = id; renderHistory(); return id; })
            .catch(() => null); // private mode etc.: fall back to in-memory only, as before
        current = entry;
    }
    const audio = $('preview-audio');
    const prev = audio.src;
    audio.src = URL.createObjectURL(blob);
    if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev);
    $('preview').hidden = false;
    setStatus('review', take ? STR.review_continue : STR.review);
}

// Drops the preview entry. A take paused behind it can't be continued any
// more, so it is closed; its history row stays.
function clearCurrent() {
    if (current && current.take) closeTake(current.take);
    current = null;
}

// ---------- takes: stop, review, continue ----------
// A take is one recording that can be stopped for review and then continued
// into the same file. MediaRecorder records a Web Audio destination rather
// than the mic stream, so the mic is released while the take waits in review
// (no "mic in use" indicator, no Bluetooth headset stuck in call mode during
// playback) and re-attached, possibly a different one, on continue. Where
// Web Audio doesn't run or the browser can't record WebM, the take records
// the mic directly and stopping ends it, as before.
const withTimeout = (promise, ms) => Promise.race([promise, new Promise((r) => setTimeout(r, ms))]);

function newTake() {
    const take = {
        mime: pickMime(), ctx: null, analyser: null, dest: null, source: null, mic: null,
        recorder: null, chunks: [], stopped: null, resumable: false, elapsed: 0, startedAt: 0, watchdog: 0,
    };
    try {
        const AC = window.AudioContext || window.webkitAudioContext;
        take.ctx = new AC(); // created inside the tap, so autoplay rules let it run
    } catch (_) {
        take.ctx = null;
    }
    return take;
}

// Routes take.mic into the take's graph: the waveform, and the recorder
// when the take is resumable.
function connectMic(take) {
    take.source = take.ctx.createMediaStreamSource(take.mic);
    take.source.connect(take.analyser);
    if (take.dest) take.source.connect(take.dest);
}

function releaseMic(take) {
    if (take.source) take.source.disconnect();
    take.source = null;
    if (take.mic) take.mic.getTracks().forEach((t) => t.stop());
    take.mic = null;
}

// First segment: picks Web Audio or direct recording, then starts.
async function startTake(take, stream) {
    take.mic = stream;
    const ctx = take.ctx;
    try {
        if (ctx && ctx.state !== 'running') await withTimeout(ctx.resume(), 500);
        if (ctx && ctx.state === 'running') {
            take.analyser = ctx.createAnalyser();
            take.analyser.fftSize = 256;
            if (take.mime.includes('webm')) {
                take.dest = ctx.createMediaStreamDestination();
                take.dest.channelCount = 1;
            }
            connectMic(take);
        }
    } catch (_) {
        // e.g. a browser refusing to mix a mic whose sample rate differs from
        // the context's: record the mic directly, without waveform or continue.
        if (take.source) take.source.disconnect();
        take.analyser = take.dest = take.source = null;
    }
    take.resumable = !!take.dest;
    if (take.resumable) {
        // Web Audio stalling mid-take (e.g. a phone call on iOS) would record
        // silence: stop instead, so what's there can be reviewed and continued.
        ctx.addEventListener('statechange', () => {
            if (recording === take && ctx.state !== 'running') stopTake(take);
        });
    }
    take.recorder = new MediaRecorder(take.resumable ? take.dest.stream : stream, take.mime ? { mimeType: take.mime } : undefined);
    take.recorder.ondataavailable = (e) => take.chunks.push(e.data);
    take.stopped = new Promise((resolve) => take.recorder.addEventListener('stop', resolve, { once: true }));
    // The recorder stopping on its own (mic gone in direct mode, an encoder
    // error) ends the take like a tap on stop would.
    take.stopped.then(() => { if (recording === take) stopTake(take); });
    take.recorder.start();
}

// Next segment of a take paused in review, on whatever mic is picked now.
async function continueTake(take, stream) {
    take.mic = stream;
    await withTimeout(take.ctx.resume(), 500);
    if (take.ctx.state !== 'running') throw new Error('AudioContext ' + take.ctx.state);
    connectMic(take);
    take.recorder.resume();
}

// ---------- mic silenced in the background ----------
// Android feeds an app that has been in the background for about a minute
// (screen gone dark, another app in front) digital silence instead of the
// mic, and the track fires no event: the take would go on recording zeros.
// So the screen is kept on while recording, and a mic that stays below
// -120 dBFS for 5 s stops the take as if tapped. That is digital silence:
// a quiet room after the browser's noise suppression is still ~-90 dBFS.
const SILENT_PEAK = 1e-6;
const SILENT_STOP_MS = 5000;
let wakeLock = null;

async function keepScreenOn() {
    if (!navigator.wakeLock || wakeLock) return;
    try {
        const lock = await navigator.wakeLock.request('screen');
        // The take may have stopped while the request was pending.
        if (!recording || wakeLock) {
            lock.release();
            return;
        }
        wakeLock = lock;
        lock.addEventListener('release', () => { if (wakeLock === lock) wakeLock = null; });
    } catch (_) {
        // Refused, e.g. battery saver: recording works without it.
    }
}

function releaseScreen() {
    if (wakeLock) wakeLock.release().catch(() => {});
    wakeLock = null;
}

// The status line can't be seen on a locked phone, a notification can.
// Permission is asked for on a record tap, the one moment it makes sense;
// asking needs that tap, and the notification needs a service worker
// (Android has no `new Notification()`).
const MIC_SILENT_TAG = 'mic-silent';

function askNotifyPermission() {
    if (window.Notification && Notification.permission === 'default') {
        try {
            Notification.requestPermission();
        } catch (_) {}
    }
}

async function notifyMicSilent() {
    if (!window.Notification || Notification.permission !== 'granted' || !navigator.serviceWorker) return;
    try {
        const reg = await navigator.serviceWorker.ready;
        await reg.showNotification(STR.mic_silent_title, {
            body: STR.mic_silent_body, tag: MIC_SILENT_TAG, icon: '/icons/icon-192.png',
        });
    } catch (_) {}
}

// Back in the app the status line says it all; the notification goes.
async function closeMicSilentNotice() {
    if (!navigator.serviceWorker) return;
    try {
        const reg = await navigator.serviceWorker.ready;
        (await reg.getNotifications({ tag: MIC_SILENT_TAG })).forEach((n) => n.close());
    } catch (_) {}
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    // The browser drops the lock whenever the page is hidden.
    if (recording) keepScreenOn();
    closeMicSilentNotice();
});

// A timer, not animation frames: those stop while the page is hidden,
// which is exactly when the mic gets silenced.
function watchSilence(take) {
    if (!take.analyser) return;
    const data = new Float32Array(take.analyser.fftSize);
    let silentSince = 0;
    take.watchdog = setInterval(() => {
        take.analyser.getFloatTimeDomainData(data);
        let peak = 0;
        for (const v of data) peak = Math.max(peak, Math.abs(v));
        const now = performance.now();
        if (peak >= SILENT_PEAK) {
            silentSince = 0;
        } else if (!silentSince) {
            silentSince = now;
        } else if (now - silentSince >= SILENT_STOP_MS && recording === take) {
            stopTake(take).then(() => setStatus('error', STR.mic_silent
                + (take.resumable ? ' — ' + STR.review_continue : '')));
            notifyMicSilent();
        }
    }, 1000);
}

function captureStarted(take) {
    recording = take;
    take.startedAt = performance.now();
    const track = take.mic.getAudioTracks()[0];
    // Mic unplugged or revoked mid-take: stop as if tapped, so what was
    // recorded lands in review (and, with Web Audio, can be continued).
    if (track) track.addEventListener('ended', () => { if (recording === take) stopTake(take); });
    watchSilence(take);
    keepScreenOn();
    startRecLive(take.analyser, take.elapsed);
    setMicLive(track);
    $('record').classList.add('recording');
    $('preview').hidden = true;
    $('import').disabled = true;
    setStatus('recording', STR.recording);
    // A continued take already has a history row; lock its actions.
    if (current && current.take === take) renderHistory();
}

// Tap on stop. A resumable take is paused and flushed (everything recorded
// so far is then in take.chunks as one valid file) and its mic released;
// any other take is stopped for good.
async function stopTake(take) {
    // Disable immediately so a rapid second tap can't continue or restart
    // the take while it is still flushing.
    $('record').disabled = true;
    recording = null;
    take.elapsed += performance.now() - take.startedAt;
    clearInterval(take.watchdog);
    releaseScreen();
    stopRecLive();
    setMicLive(null);
    if (take.resumable && take.recorder.state === 'recording') {
        await new Promise((resolve) => {
            take.recorder.addEventListener('dataavailable', resolve, { once: true });
            take.recorder.pause();
            take.recorder.requestData();
        });
        releaseMic(take);
        take.ctx.suspend().catch(() => {}); // closed meanwhile, e.g. history cleared
    } else {
        take.resumable = false;
        if (take.recorder.state !== 'inactive') take.recorder.stop();
        await take.stopped;
        closeTake(take);
    }
    const type = (take.recorder.mimeType || 'audio/webm').split(';')[0];
    let blob = new Blob(take.chunks, { type });
    if (take.resumable) blob = await dropWebmDuration(blob);
    showPreview(blob, type, take.resumable ? take : null);
    $('record').classList.remove('recording');
    $('record').disabled = false;
    $('import').disabled = false;
}

// Chrome writes a placeholder Duration (1 ms) into the WebM header and only
// corrects it when the whole file comes out at once on stop. A paused take
// is flushed mid-recording, so the placeholder stays and players (and
// possibly the transcription) would take the file for 1 ms long. Turning
// the element into an EBML Void of the same size leaves the duration
// unknown, as in any live WebM, so it is read from the audio itself.
// Anything that doesn't parse as expected is returned untouched.
async function dropWebmDuration(blob) {
    const b = new Uint8Array(await blob.slice(0, 1024).arrayBuffer());
    // EBML variable-length int at i; IDs keep their length marker bits.
    const vint = (i, isId) => {
        let len = 1;
        while (len <= 8 && !(b[i] & (0x100 >> len))) len++;
        if (len > 8 || i + len > b.length) return null;
        let v = isId ? b[i] : b[i] & ((0x100 >> len) - 1);
        for (let k = 1; k < len; k++) v = v * 256 + b[i + k];
        return { v, len };
    };
    // First element with this ID among the siblings in [start, end).
    const find = (start, end, id) => {
        for (let i = start; i < end;) {
            const elId = vint(i, true);
            const size = elId && vint(i + elId.len, false);
            if (!size) return null;
            const body = i + elId.len + size.len;
            if (elId.v === id) return { at: i, body, end: body + size.v };
            i = body + size.v;
        }
        return null;
    };
    const segment = find(0, b.length, 0x18538067);
    const info = segment && find(segment.body, b.length, 0x1549a966);
    const duration = info && find(info.body, Math.min(info.end, b.length), 0x4489);
    if (!duration || duration.end > b.length) return blob;
    const total = duration.end - duration.at;
    const head = b.slice(0, duration.end);
    head.fill(0, duration.at);
    head[duration.at] = 0xec;                // Void
    head[duration.at + 1] = 0x80 | (total - 2); // size of the filler that follows
    return new Blob([head, blob.slice(duration.end)], { type: blob.type });
}

// Ends a take for good. A paused take's file is already complete (pausing
// flushed it), so whatever the final stop still delivers is dropped.
function closeTake(take) {
    if (recording === take) {
        // Ended mid-recording, e.g. its history row was deleted meanwhile.
        recording = null;
        clearInterval(take.watchdog);
        releaseScreen();
        stopRecLive();
        setMicLive(null);
        $('record').classList.remove('recording');
        $('import').disabled = false;
    }
    releaseMic(take);
    if (take.recorder && take.recorder.state !== 'inactive') take.recorder.stop();
    if (take.ctx && take.ctx.state !== 'closed') take.ctx.close();
}

let starting = false; // a tap is waiting for the mic; ignore further taps

$('record').addEventListener('click', async () => {
    if (recording) {
        stopTake(recording);
        return;
    }
    if (starting) return;
    starting = true;
    // A take stopped for review is continued; otherwise a new one starts.
    const paused = current && current.take;
    const take = paused || newTake();
    if (take.ctx) take.ctx.resume().catch(() => {}); // still inside the tap (Safari)
    askNotifyPermission(); // likewise; not awaited, recording doesn't wait for the answer
    try {
        const audio = micsShown ? { deviceId: { exact: $('mic-select').value } } : true;
        const stream = await navigator.mediaDevices.getUserMedia({ audio });
        // Permission is granted now, so labels are readable: this is where
        // the picker first shows up on a fresh install.
        await refreshMics();
        if (paused) await continueTake(take, stream);
        else await startTake(take, stream);
        captureStarted(take);
    } catch (err) {
        // A paused take stays in review, ready for another try.
        if (paused) {
            releaseMic(take);
            take.ctx.suspend().catch(() => {});
        } else {
            closeTake(take);
        }
        setStatus('error', STR.mic_error + ': ' + err.message);
    } finally {
        starting = false;
    }
});

$('import').addEventListener('click', () => {
    if (recording) return;
    $('import-audio').click();
});

// Validates an external audio file (file picker or Web Share Target) and
// opens it in the preview, or reports why it was rejected.
function importFile(file) {
    const mime = mimeFromFile(file);
    if (!mime || file.size === 0 || file.size > MAX_IMPORT_BYTES) {
        setStatus('error', STR.import_error);
        return;
    }
    showPreview(file, mime);
}

$('import-audio').addEventListener('change', () => {
    const input = $('import-audio');
    const file = input.files && input.files[0];
    input.value = '';
    if (file) importFile(file);
});

$('discard').addEventListener('click', async () => {
    const entry = current;
    clearCurrent();
    $('preview').hidden = true;
    setStatus('ready', STR.ready);
    // Explicit discard is the one case where the persisted row should go.
    if (entry) {
        const id = await entry.persist;
        if (id !== null) {
            await dbDelete(id);
            renderHistory();
        }
    }
});

// If `id` is the entry currently open in the preview, close the preview and
// clear `current` — history and preview must never disagree about the same
// row (e.g. it was sent or deleted from the history list instead).
function closePreviewIfCurrent(id) {
    if (current && current.id === id) {
        clearCurrent();
        $('preview').hidden = true;
        setStatus('ready', STR.ready);
    }
}

$('share-preview').addEventListener('click', () => {
    if (current) shareEntry(current);
});

$('download-preview').addEventListener('click', () => {
    if (current) downloadEntry(current);
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
    const entry = current;
    const { blob, mime, createdAt } = entry;

    $('preview').hidden = true;
    clearCurrent();
    setStatus('sending', STR.sending);

    // Wait for the 'unsent' row from showPreview before flipping its status.
    let id = await entry.persist;
    if (id !== null) {
        await dbUpdate(id, { status: 'sending' });
    } else {
        // showPreview's dbAdd failed (e.g. private browsing): create the row
        // here instead, exactly as before recordings were persisted early.
        try {
            id = await dbAdd({ blob, mime, createdAt, status: 'sending' });
        } catch (_) {
            id = null;
        }
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
const SVG_PAUSE = '<svg class="icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3.4v14H7zM13.6 5H17v14h-3.4z"/></svg>';
const SVG_RETRY = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
const SVG_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
const SVG_SHARE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
const SVG_DOWNLOAD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';

// One shared player for history entries so plays never overlap and object
// URLs get revoked when playback stops. The expanded panel (seek + times +
// rate) lives under whichever entry is active.
let historyAudio = null;
let historyPlayBtn = null;
let historyEntryLi = null;
let historyPanel = null;

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
    if (historyPanel) {
        historyPanel.remove();
        historyPanel = null;
    }
    if (historyEntryLi) {
        historyEntryLi.classList.remove('expanded');
        historyEntryLi = null;
    }
}

function openEntryPlayer(li, entry, playBtn) {
    const audio = new Audio(URL.createObjectURL(entry.blob));
    historyAudio = audio;
    historyPlayBtn = playBtn;
    historyEntryLi = li;
    li.classList.add('expanded');

    const root = document.createElement('div');
    root.className = 'entry-player';

    const seek = document.createElement('input');
    seek.type = 'range';
    seek.className = 'seek';
    seek.min = '0';
    seek.max = '100';
    seek.step = '0.1';
    seek.value = '0';
    seek.setAttribute('aria-label', STR.seek);
    root.appendChild(seek);

    const row = document.createElement('div');
    row.className = 'entry-player-row';

    const time = document.createElement('span');
    time.className = 'entry-player-time';
    time.textContent = '0:00 / 0:00';
    row.appendChild(time);

    const rate = document.createElement('button');
    rate.type = 'button';
    rate.className = 'rate-btn';
    rate.setAttribute('aria-label', STR.playback_speed);
    initRateButton(rate, () => (historyAudio === audio ? audio : null));
    row.appendChild(rate);

    root.appendChild(row);
    li.appendChild(root);
    historyPanel = root;

    let duration = 0;
    const render = () => {
        const pct = duration > 0 ? Math.min(100, (audio.currentTime / duration) * 100) : 0;
        seek.value = String(pct);
        seek.style.setProperty('--fill', pct + '%');
        time.textContent = fmtTime(duration > 0 ? audio.currentTime : 0) + ' / ' + fmtTime(duration);
    };
    render();

    const setPaused = (paused) => {
        playBtn.classList.toggle('playing', !paused);
        playBtn.setAttribute('aria-label', paused ? STR.play_recording : STR.pause);
    };

    audio.addEventListener('loadedmetadata', () => {
        audio.playbackRate = savedRate();
        resolveDuration(audio, (d) => {
            duration = d;
            render();
            audio.play();
        });
    });
    audio.addEventListener('timeupdate', () => { if (duration > 0) render(); });
    audio.addEventListener('play', () => setPaused(false));
    audio.addEventListener('pause', () => setPaused(true));
    audio.addEventListener('ended', () => {
        audio.currentTime = 0;
        render();
    });
    audio.addEventListener('error', stopHistoryPlayback);

    seek.addEventListener('input', () => {
        if (duration > 0) {
            audio.currentTime = (Number(seek.value) / 100) * duration;
            render();
        } else {
            seek.value = '0';
        }
    });

    setPaused(false); // optimistic; the play/pause events keep it in sync
}

$('clear-history').addEventListener('click', async () => {
    if (!confirm(STR.confirm_delete_all)) return;
    await dbClear();
    // The preview's row is gone too, so the preview can't stay open.
    if (current) {
        clearCurrent();
        $('preview').hidden = true;
        setStatus('ready', STR.ready);
    }
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

// Share buttons are only shown where the Web Share API exists; Download is
// always available because Android share sheets offer no "save locally".
const CAN_SHARE = typeof navigator.share === 'function';

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
        play.innerHTML = SVG_PLAY + SVG_PAUSE;
        play.addEventListener('click', () => {
            if (historyPlayBtn === play && historyAudio) {
                // Same entry: toggle play/pause; the panel stays open.
                if (historyAudio.paused) historyAudio.play();
                else historyAudio.pause();
                return;
            }
            stopHistoryPlayback();
            openEntryPlayer(li, entry, play);
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
        const unsent = entry.status === 'unsent';
        badge.className = entry.status === 'sent' ? 'badge ok' : unsent ? 'badge warn' : 'badge err';
        badge.textContent = entry.status === 'sent' ? STR.badge_sent : unsent ? STR.badge_unsent : STR.badge_error;
        meta.appendChild(badge);

        li.appendChild(meta);

        const actions = document.createElement('div');
        actions.className = 'entry-actions';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-ghost btn-retry';
        retry.innerHTML = SVG_RETRY + '<span></span>';
        retry.querySelector('span').textContent = unsent ? STR.send : STR.retry;
        retry.addEventListener('click', async () => {
            if (entry.status === 'sent' && !confirm(STR.confirm_resend_duplicate)) return;
            retry.disabled = true;
            closePreviewIfCurrent(entry.id); // this row is leaving 'unsent'
            setStatus('sending', STR.sending);
            try {
                await upload(entry.blob, entry.mime);
                await dbUpdate(entry.id, { status: 'sent' });
                setStatus('sent', unsent ? STR.sent : STR.resent);
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
        share.hidden = !CAN_SHARE;
        actions.appendChild(share);

        const download = document.createElement('button');
        download.type = 'button';
        download.className = 'icon-btn icon-btn-sm';
        download.setAttribute('aria-label', STR.download_recording);
        download.innerHTML = SVG_DOWNLOAD;
        download.addEventListener('click', () => downloadEntry(entry));
        actions.appendChild(download);

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'icon-btn icon-btn-sm icon-btn-danger';
        del.setAttribute('aria-label', STR.delete_recording);
        del.innerHTML = SVG_TRASH;
        del.addEventListener('click', async () => {
            if (!confirm(STR.confirm_delete)) return;
            await dbDelete(entry.id);
            closePreviewIfCurrent(entry.id);
            renderHistory();
        });
        actions.appendChild(del);

        // The row of a take being continued right now holds only its earlier
        // part: sending or deleting it would cut the take off mid-recording.
        if (recording && current && current.take === recording && current.id === entry.id) {
            retry.disabled = true;
            del.disabled = true;
        }

        li.appendChild(actions);
        list.appendChild(li);
    }
}

// ---------- shared player helpers ----------
const PLAYBACK_RATES = [1, 1.25, 1.5, 2];

function fmtTime(s) {
    if (!Number.isFinite(s) || s < 0) s = 0;
    return Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');
}

function savedRate() {
    const r = parseFloat(localStorage.getItem('vv_rate'));
    return PLAYBACK_RATES.includes(r) ? r : 1;
}

function rateLabel(r) {
    return r + '×';
}

// Cycles 1× → 1.25× → 1.5× → 2× → 1×, persists the choice, and applies it
// to whatever audio element getAudio() currently returns (null = none).
function initRateButton(btn, getAudio) {
    btn.textContent = rateLabel(savedRate());
    btn.addEventListener('click', () => {
        const next = PLAYBACK_RATES[(PLAYBACK_RATES.indexOf(savedRate()) + 1) % PLAYBACK_RATES.length];
        localStorage.setItem('vv_rate', String(next));
        btn.textContent = rateLabel(next);
        const audio = getAudio();
        if (audio) audio.playbackRate = next;
    });
}

// Chrome reports Infinity for fresh MediaRecorder blobs; seeking far past
// the end forces the real duration to be computed. Calls cb once known.
function resolveDuration(audio, cb) {
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
        cb(audio.duration);
        return;
    }
    const probe = () => {
        if (Number.isFinite(audio.duration) && audio.duration > 0) {
            audio.removeEventListener('durationchange', probe);
            audio.currentTime = 0;
            cb(audio.duration);
        }
    };
    audio.addEventListener('durationchange', probe);
    audio.currentTime = 1e7;
}

// ---------- presentation: preview player ----------
// Minimal custom player over the (controls-less) #preview-audio element.
(() => {
    const audio = $('preview-audio');
    const toggle = $('player-toggle');
    const seek = $('player-seek');
    const time = $('player-time');
    let duration = 0;

    const setPlaying = (playing) => {
        toggle.classList.toggle('playing', playing);
        toggle.setAttribute('aria-label', playing ? STR.pause : STR.play);
    };

    const render = () => {
        const pct = duration > 0 ? Math.min(100, (audio.currentTime / duration) * 100) : 0;
        seek.value = String(pct);
        seek.style.setProperty('--fill', pct + '%');
        time.textContent = fmtTime(duration > 0 ? audio.currentTime : 0) + ' / ' + fmtTime(duration);
    };

    audio.addEventListener('loadedmetadata', () => {
        setPlaying(false);
        duration = 0;
        render();
        audio.playbackRate = savedRate();
        $('player-rate').textContent = rateLabel(savedRate());
        resolveDuration(audio, (d) => {
            duration = d;
            render();
        });
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

    initRateButton($('player-rate'), () => audio);
})();

// ---------- Web Share Target ----------
// Android hands a shared file to the service worker (POST /share-target),
// which parks it in the Cache API and redirects here with ?share-target=1.
// Keep SHARE_CACHE / SHARE_KEY in sync with public/sw.js.
const SHARE_CACHE = 'vv-share';
const SHARE_KEY = '/share-target/file';
let pendingShare = null; // shared file waiting for the token to be entered

async function consumeSharedFile() {
    const params = new URLSearchParams(location.search);
    if (!params.has('share-target')) return;
    // Drop the marker so a reload doesn't run this again.
    history.replaceState(null, '', location.pathname);

    let file = null;
    if (params.get('share-target') === '1' && window.caches) {
        try {
            const cache = await caches.open(SHARE_CACHE);
            const res = await cache.match(SHARE_KEY);
            if (res) {
                await cache.delete(SHARE_KEY);
                const blob = await res.blob();
                const name = decodeURIComponent(res.headers.get('X-File-Name') || '');
                file = new File([blob], name || 'shared', { type: res.headers.get('Content-Type') || blob.type });
            }
        } catch (_) {
            file = null;
        }
    }

    if (!file) {
        setStatus('error', STR.share_target_error);
        return;
    }
    if (!token) {
        pendingShare = file;
        return;
    }
    importFile(file);
}

// ---------- init ----------
showSections();
renderHistory();
$('share-preview').hidden = !CAN_SHARE;
consumeSharedFile();
refreshMics();
if (navigator.mediaDevices) navigator.mediaDevices.addEventListener('devicechange', refreshMics);

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
