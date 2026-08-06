<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#0b0d12">
    <title>Vikunja Voice</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <main>
        <header class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            </span>
            <h1>Vikunja Voice</h1>
        </header>

        <section id="token-section" class="card" hidden>
            <label class="field-label" for="token-input">Zadej upload token</label>
            <form id="token-form">
                <input type="password" id="token-input" autocomplete="off" required>
                <button type="submit" class="btn btn-primary">Uložit</button>
            </form>
        </section>

        <section id="recorder-section" class="card card-recorder" hidden>
            <button id="record" type="button" aria-label="Nahrávat">
                <svg class="icon-mic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                <svg class="icon-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6.5" y="6.5" width="11" height="11" rx="2"/></svg>
            </button>
            <p id="status" role="status" data-state="ready">Připraveno</p>

            <div id="preview" hidden>
                <div class="player">
                    <button id="player-toggle" class="player-btn" type="button" aria-label="Přehrát">
                        <svg class="icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.14v13.72L19 12z"/></svg>
                        <svg class="icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3.4v14H7zM13.6 5H17v14h-3.4z"/></svg>
                    </button>
                    <input id="player-seek" type="range" min="0" max="100" step="0.1" value="0" aria-label="Pozice v nahrávce">
                    <span id="player-time">0:00 / 0:00</span>
                    <audio id="preview-audio"></audio>
                </div>
                <div class="preview-actions">
                    <button id="send" type="button" class="btn btn-primary">Odeslat</button>
                    <button id="discard" type="button" class="btn btn-ghost">Zahodit</button>
                </div>
            </div>
        </section>

        <section id="history-section" class="card" hidden>
            <h2>Historie <span class="h2-note">jen v tomto prohlížeči</span></h2>
            <ul id="history"></ul>
        </section>
    </main>
    <script src="/js/app.js"></script>
</body>
</html>
