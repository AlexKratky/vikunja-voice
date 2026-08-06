# vikunja-voice

[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Voice-to-task for self-hosted [Vikunja](https://vikunja.io/). Dictate a note in a minimal recorder PWA; the server transcribes it with Google Gemini, extracts a structured task (title, due date, priority, project, labels), and creates it via the Vikunja API.

No recording is ever lost: the audio file is stored before processing, the job retries 5 times with backoff, and permanently failed jobs stay in `failed_jobs` for manual replay.

## How it works

```
Browser PWA ──POST /api/recordings──▶ Laravel ──▶ storage (private) ──▶ queued job
 (Bearer token)                                                            │
                    ntfy ◀── Vikunja task ◀── validation ◀── Gemini ◀──────┘
                                              (against live projects/labels)
```

1. The PWA records audio (`MediaRecorder`) and uploads it with a shared bearer token. Recording history is kept only in your browser (IndexedDB), with retry for failed uploads.
2. A queued job sends the audio to Gemini with a structured-output schema: verbatim transcript plus task fields. Relative dates ("tomorrow", "by Friday") are resolved against the configured timezone.
3. Extracted project and label IDs are validated against your actual Vikunja data — the model cannot invent IDs. The task is created with the transcript preserved in its description.
4. Optionally, a [ntfy](https://ntfy.sh/) notification confirms success or failure.

## Features

- One-tap recorder PWA, installable on mobile, with browser-local history and retry
- Structured extraction: title, description, due date, priority (Vikunja 0–5 scale), project, labels
- Auto-assign every task to a configured user and/or auto-attach a configured label
- Daily retention pruning of processed audio files
- Single-user by design: one shared upload token, no accounts, and the server exposes **no endpoint to read, list, or download recordings**

## Language

`VOICE_INPUT_LOCALE` sets the language of the whole pipeline: the Gemini extraction prompt (i.e. the language you dictate in), the recorder UI, and notification/transcript texts. Supported values are `en` (default) and `cs`; anything else falls back to English.

Adding a language takes two focused changes — a `lang/<locale>/voice.php` translation file and a system prompt in `app/Services/GeminiClient.php`. PRs welcome.

> [!NOTE]
> **Upgrading from a pre-locale version?** The default is English. Set `VOICE_INPUT_LOCALE=cs` in `.env` to keep the original Czech behavior.

## Requirements

- PHP 8.4, Composer
- SQLite (default; anything Laravel supports works)
- A Vikunja instance and API token
- A Google Gemini API key ([Google AI Studio](https://aistudio.google.com/))
- HTTPS in production (the microphone requires a secure context)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php scripts/make-icons.php
```

Then fill in `.env`:

| Variable | Description |
|---|---|
| `APP_UPLOAD_TOKEN` | long random upload token — generate with `openssl rand -hex 32` |
| `VOICE_INPUT_LOCALE` | language of dictation, UI, and notifications: `en` (default) or `cs` |
| `GEMINI_API_KEY` | key from Google AI Studio |
| `GEMINI_MODEL` | default `gemini-2.5-flash` |
| `VIKUNJA_URL` | e.g. `https://vikunja.example.com` |
| `VIKUNJA_API_TOKEN` | Vikunja → Settings → API tokens (scopes: projects read, tasks write, labels read/write) |
| `VIKUNJA_DEFAULT_PROJECT_ID` | inbox project ID used as fallback |
| `VIKUNJA_AUTO_ASSIGN_USERNAME` | user every task gets assigned to (empty = off) |
| `VIKUNJA_AUTO_LABEL` | label title attached to every task (empty = off) |
| `NTFY_TOPIC` | ntfy topic for notifications (empty = off) — **see the privacy note below** |
| `NTFY_URL` | ntfy server, default `https://ntfy.sh` |
| `NTFY_NOTIFY_SUCCESS` | also notify on success, default `true` |
| `RETENTION_DAYS` | days after which processed audio is deleted (default 7) |
| `VOICE_TIMEZONE` | timezone for due-date parsing, default `Europe/Prague` |

Run the dev stack with `php artisan serve` plus `php artisan queue:work`; tests with `php artisan test`.

## Deployment

Applies to both variants below:

- Set `APP_ENV=production` and `APP_DEBUG=false`. With debug enabled, any error page leaks stack traces and configuration — **including the API keys from `.env`**.
- The web server document root must be the `public/` directory, never the repo root. Pointing it at the repo root would expose `.env`, the SQLite database, and the stored recordings.
- Serve over HTTPS only.

### Coolify (Railpack build pack)

Railpack detects Laravel automatically (composer install, `npm run build`, migrations on boot) and serves the app via FrankenPHP/Caddy. The repo ships `start-container.sh`, which additionally runs the queue worker and scheduler in the same container — a single Coolify application is enough, no separate worker.

1. New application from the git repo, **Build Pack: Railpack**.
2. **Persistent Storage**: volume mount at `/app/storage` (recordings + SQLite must survive redeploys).
3. **Environment Variables**: everything from the table above, plus:
   - `APP_KEY` — generate locally with `php artisan key:generate --show`
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`
   - `DB_CONNECTION=sqlite`, `DB_DATABASE=/app/storage/database.sqlite` — an absolute path on the volume; the default `database/database.sqlite` would be wiped on redeploy
   - `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `DB_QUEUE_RETRY_AFTER=330`
4. Point a domain at it — the Coolify proxy handles HTTPS. Health checks can target `/up`.

### VPS (nginx + php-fpm)

Queue worker (systemd):

```ini
[Unit]
Description=vikunja-voice queue worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/vikunja-voice/artisan queue:work --tries=5 --timeout=300

[Install]
WantedBy=multi-user.target
```

Scheduler (cron):

```
* * * * * cd /var/www/vikunja-voice && php artisan schedule:run >> /dev/null 2>&1
```

`DB_QUEUE_RETRY_AFTER` (in `.env`) must stay higher than the job timeout (`--timeout=300` above), otherwise a second worker can "steal" a running job and process it twice.

## Security & privacy

- **Your audio is sent to Google Gemini** for transcription and extraction. If that is not acceptable to you, this tool is not for you.
- Uploads are protected by a single bearer token compared with `hash_equals`; the endpoint fails closed when no token is configured. There are no accounts and no server-side way to read recordings back — the API only ever answers `202`/`4xx`.
- Recording history lives only in your browser (IndexedDB); the upload token is kept in `localStorage`.
- **Retention:** the daily `recordings:prune` command deletes audio files of *successfully processed* recordings after `RETENTION_DAYS`. Transcripts remain in the SQLite database and in the created Vikunja tasks. Audio of *failed* recordings is kept until you retry or delete it manually — by design, so nothing is lost.

> [!WARNING]
> **ntfy privacy.** On the public `ntfy.sh` server, the topic name is the only thing protecting your notifications — anyone who knows or guesses it can subscribe (and publish). Notifications include the extracted task title, and failure notifications can include error details. If you use ntfy, prefer a self-hosted instance or an [access-protected topic](https://docs.ntfy.sh/publish/#access-tokens), or at minimum use a long random topic name (`openssl rand -hex 16`) and consider `NTFY_NOTIFY_SUCCESS=false`. Leaving `NTFY_TOPIC` empty disables notifications entirely.

## When something fails

- After 5 failed attempts the job lands in `failed_jobs` and a ntfy notification is sent (if configured). The audio stays on disk — failed recordings are never auto-deleted.
- Reprocess: `php artisan queue:retry all`
- Inspect: `php artisan queue:failed`
- Processing is at-least-once: if the worker dies right after creating the Vikunja task but before recording it in the DB, a retry can exceptionally create a duplicate task — nothing is ever lost; a duplicate always beats a lost note.

## Contributing

Issues and pull requests are welcome at [AlexKratky/vikunja-voice](https://github.com/AlexKratky/vikunja-voice). This is a small personal tool — please keep changes focused.

## License

[MIT](LICENSE)
