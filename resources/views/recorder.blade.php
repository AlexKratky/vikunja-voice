<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#18181b">
    <title>vikunja-voice</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <main>
        <h1>vikunja-voice</h1>

        <section id="token-section" hidden>
            <p>Zadej upload token:</p>
            <form id="token-form">
                <input type="password" id="token-input" autocomplete="off" required>
                <button type="submit">Uložit</button>
            </form>
        </section>

        <section id="recorder-section" hidden>
            <button id="record" type="button" aria-label="Nahrávat">●</button>
            <p id="status">Připraveno</p>

            <div id="preview" hidden>
                <audio id="preview-audio" controls></audio>
                <div class="preview-actions">
                    <button id="send" type="button">Odeslat</button>
                    <button id="discard" type="button">Zahodit</button>
                </div>
            </div>
        </section>

        <section id="history-section" hidden>
            <h2>Historie (jen v tomto prohlížeči)</h2>
            <ul id="history"></ul>
        </section>
    </main>
    <script src="/js/app.js"></script>
</body>
</html>
