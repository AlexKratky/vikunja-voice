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

    const id = await dbAdd({ blob, mime, createdAt: Date.now(), status: 'sending' });

    try {
        await upload(blob, mime);
        await dbUpdate(id, { status: 'sent' });
        $('status').textContent = 'Odesláno ✓ (zpracuje se na pozadí)';
    } catch (err) {
        await dbUpdate(id, { status: 'error' });
        $('status').textContent = 'Chyba odeslání: ' + err.message + ' — v historii jde poslat znovu';
    }

    renderHistory();
});

// ---------- history ----------
async function renderHistory() {
    const list = $('history');
    list.innerHTML = '';
    const entries = await dbAll();

    for (const entry of entries) {
        const li = document.createElement('li');

        const when = new Date(entry.createdAt).toLocaleString('cs-CZ', {
            day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit',
        });
        const label = document.createElement('span');
        label.innerHTML = entry.status === 'sent'
            ? `${when} <span class="ok">odesláno</span>`
            : `${when} <span class="err">chyba</span>`;
        li.appendChild(label);

        const actions = document.createElement('span');

        const play = document.createElement('button');
        play.textContent = '▶';
        play.addEventListener('click', () => new Audio(URL.createObjectURL(entry.blob)).play());
        actions.appendChild(play);

        if (entry.status !== 'sent') {
            const retry = document.createElement('button');
            retry.textContent = 'Znovu';
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
            actions.appendChild(retry);
        }

        li.appendChild(actions);
        list.appendChild(li);
    }
}

// ---------- init ----------
showSections();
renderHistory();

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}
