<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#0b0d12">
    <title>Vikunja Voice</title>
    <meta name="apple-mobile-web-app-title" content="Vikunja Voice">
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
            <label class="field-label" for="token-input">{{ __('voice.ui.token_label') }}</label>
            <form id="token-form">
                <input type="password" id="token-input" autocomplete="off" required>
                <button type="submit" class="btn btn-primary">{{ __('voice.ui.save') }}</button>
            </form>
        </section>

        <section id="recorder-section" class="card card-recorder" hidden>
            <button id="record" type="button" aria-label="{{ __('voice.js.record') }}">
                <svg class="icon-mic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                <svg class="icon-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="6.5" y="6.5" width="11" height="11" rx="2"/></svg>
            </button>
            <p id="status" role="status" data-state="ready">{{ __('voice.js.ready') }}</p>

            <div id="preview" hidden>
                <div class="player">
                    <button id="player-toggle" class="player-btn" type="button" aria-label="{{ __('voice.js.play') }}">
                        <svg class="icon-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.14v13.72L19 12z"/></svg>
                        <svg class="icon-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3.4v14H7zM13.6 5H17v14h-3.4z"/></svg>
                    </button>
                    <input id="player-seek" type="range" min="0" max="100" step="0.1" value="0" aria-label="{{ __('voice.ui.seek') }}">
                    <span id="player-time">0:00 / 0:00</span>
                    <audio id="preview-audio"></audio>
                </div>
                <div class="preview-actions">
                    <button id="send" type="button" class="btn btn-primary">{{ __('voice.ui.send') }}</button>
                    <button id="discard" type="button" class="btn btn-ghost">{{ __('voice.ui.discard') }}</button>
                </div>
            </div>
        </section>

        <section id="history-section" class="card" hidden>
            <div class="history-head">
                <h2>{{ __('voice.ui.history') }} <span class="h2-note">{{ __('voice.ui.history_note') }}</span></h2>
                <button id="clear-history" type="button" class="btn btn-ghost btn-clear" hidden>{{ __('voice.ui.delete_all') }}</button>
            </div>
            <ul id="history"></ul>
        </section>
    </main>
    <script>window.VV = @json(['locale' => app()->getLocale(), 'strings' => __('voice.js')]);</script>
    <script src="/js/app.js"></script>
</body>
</html>
