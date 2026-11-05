# vikunja-voice

[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Voice-to-task for self-hosted [Vikunja](https://vikunja.io/). Dictate a note in a minimal recorder PWA; the server transcribes it with Google Gemini, extracts one or more structured tasks (title, due date, priority, project, labels), and creates them via the Vikunja API.

No recording is ever lost: the audio file is stored before processing, the job retries 5 times with backoff, and permanently failed jobs stay in `failed_jobs` for manual replay.

## How it works

```
Browser PWA ──POST /api/recordings──▶ Laravel ──▶ storage (private) ──▶ queued job
 (Bearer token)                                                            │
                    ntfy ◀── Vikunja task ◀── validation ◀── Gemini ◀──────┘
                                              (against live projects/labels)
```

1. The PWA records audio (`MediaRecorder`) and uploads it with a shared bearer token. Recording history is kept only in your browser (IndexedDB): a recording lands there as soon as you stop recording, marked *not sent* until you send it, so closing the app before sending never loses it. Failed uploads can be retried from the history.
2. A queued job sends the audio to Gemini with a structured-output schema: a verbatim transcript plus a list of tasks. One recording is one task by default; the model splits it only when you explicitly say "new task" / "další úkol" while dictating. A recording with no speech (silence, noise, a mic test) yields no task rather than an invented one. Relative dates ("tomorrow", "by Friday") are resolved against the configured timezone.
3. Extracted project and label IDs are validated against your actual Vikunja data — the model cannot invent IDs. Each task is created with its part of the transcript preserved in its description (with a single task, the whole transcript). A [voice command](#voice-commands) can instead act on one of your existing tasks.
4. Optionally, a [ntfy](https://ntfy.sh/) notification confirms success or failure, or says that no task was found (sent even with `NTFY_NOTIFY_SUCCESS=false`).

A typed note (`POST /api/notes`) takes the same path: it is stored first, then the same job sends its text to Gemini instead of audio. The text is kept as the transcript, so the extraction rules (one task unless you write "new task", relative dates, project and label matching) are identical.

## API

Both endpoints require `Authorization: Bearer <APP_UPLOAD_TOKEN>` and answer `401` without it. They only accept work and never return recordings or notes back.

| Endpoint | Body | Success |
|---|---|---|
| `POST /api/recordings` | `multipart/form-data`, field `audio`: WebM, Ogg, M4A/MP4, MP3 or WAV, max 20 MB | `202 {"id": "<uuid>"}` |
| `POST /api/notes` | JSON `{"text": "..."}`, max 2000 characters | `202 {"id": "<uuid>"}` |

Invalid input gets `422` with Laravel's JSON validation errors. A `202` means the item is stored and queued; the result arrives in Vikunja (and via ntfy) a few seconds later.

## Features

- One-tap recorder PWA, installable on mobile, with browser-local history and retry
- Microphone picker when the device has more than one input; while recording it shows which input is live
- Stop and continue: in review, tap the mic again to add more to the same recording; the mic is released in between
- Keeps the screen on while recording. If the phone silences the mic anyway (Android does that to an app left in the background for about a minute), the recording stops for review instead of filling up with silence, with a notification that shows on the lock screen (permission is asked on the first recording)
- Accepts audio shared from other Android apps (Web Share Target), and can share or download recordings back out
- Structured extraction: title, description, due date, priority (Vikunja 0–5 scale), project, labels
- Several tasks from one recording: say "new task" out loud and keep dictating; a change of topic alone never splits
- [Voice commands](#voice-commands), each with its own switch: create a task only if it doesn't exist yet
- Auto-assign every task to a configured user and/or auto-attach a configured label
- Daily retention pruning of processed audio files
- Typed notes over the same pipeline (`POST /api/notes`), e.g. from a dashboard or a script
- Single-user by design: one shared upload token, no accounts, and the server exposes **no endpoint to read, list, or download recordings**

## Voice commands

Besides dictating new tasks, you can say a command. Every command is on by default and has its own switch in `.env` (`true`/`false`); a switched-off command is left out of the prompt entirely, so its phrase is dictated as an ordinary task. Commands and tasks mix freely in one recording — a different command starts a new item without saying "new task".

| Command | Say (en / cs) | What happens | Switch |
|---|---|---|---|
| Create if missing | "create a task if it doesn't exist: …" / „založ úkol, pokud neexistuje: …" | The task is created only if none of your open tasks is the same one; otherwise nothing is created and the notification links to the existing task. When unsure, the task is created — a duplicate beats a lost note. | `VOICE_COMMAND_CREATE_IF_MISSING` |

**Finding the existing task.** A command about an existing task is compared with your open tasks: the 50 newest plus those containing any of the keywords the model picked from the dictation, each searched on its own in titles and descriptions (Vikunja's search takes a phrase as one substring). A second, text-only Gemini request picks the task — only ever one of those, and none when unsure. The decision is stored before anything is done, so a retry never asks again. Recordings without such a command never read your task list.

**Notifications.** Only a plain new task follows `NTFY_NOTIFY_SUCCESS`. Everything else is always announced, since either nothing new shows up in Vikunja or the model picked the task acted on; the notification links to that task so you can check.

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
| `VIKUNJA_API_TOKEN` | Vikunja → Settings → API tokens (scopes: projects read, tasks write, labels read/write; the [voice commands](#voice-commands) also need tasks read all) |
| `VIKUNJA_DEFAULT_PROJECT_ID` | inbox project ID used as fallback |
| `VIKUNJA_AUTO_ASSIGN_USERNAME` | user every task gets assigned to (empty = off) |
| `VIKUNJA_AUTO_LABEL` | label title attached to every task (empty = off) |
| `NTFY_TOPIC` | ntfy topic for notifications (empty = off) — **see the privacy note below** |
| `NTFY_URL` | ntfy server, default `https://ntfy.sh` |
| `NTFY_NOTIFY_SUCCESS` | also notify on success, default `true` |
| `VOICE_COMMAND_*` | switches for the [voice commands](#voice-commands), all `true` by default |
| `KUMA_PUSH_URL` | [Uptime Kuma](https://github.com/louislam/uptime-kuma) push monitor URL for the queue heartbeat (empty = off), see below |
| `RETENTION_DAYS` | days after which processed audio is deleted (default 7) |
| `VOICE_TIMEZONE` | timezone for due-date parsing, default `Europe/Prague` |
| `SENTRY_LARAVEL_DSN` | Sentry DSN for backend error reporting (empty = off). A recording that exhausts all 5 processing attempts is reported as a `fatal` event tagged `terminal_failure: true` — point a Sentry alert rule at that tag. |

Run the dev stack with `php artisan serve` plus `php artisan queue:work`; tests with `php artisan test`.

## Deployment

Applies to both variants below:

- Set `APP_ENV=production` and `APP_DEBUG=false`. With debug enabled, any error page leaks stack traces and configuration — **including the API keys from `.env`**.
- The web server document root must be the `public/` directory, never the repo root. Pointing it at the repo root would expose `.env`, the SQLite database, and the stored recordings.
- Serve over HTTPS only.
- Upgrading to the version with typed notes? The migration makes the audio `path` and `mime` columns nullable, which SQLite does by rebuilding the `recordings` table. Existing rows are copied as they are; a backup of `storage/database.sqlite` beforehand does not hurt.
- Upgrading from a version with a single `vikunja_task_id` column? Back up `storage/database.sqlite` first: the migration moves the IDs into `vikunja_task_ids` and drops the old column.

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

- **Your audio and typed notes are sent to Google Gemini** for transcription and extraction. If that is not acceptable to you, this tool is not for you.
- A [voice command](#voice-commands) about an existing task also sends Gemini the titles and project names of the open tasks it is compared with (the 50 newest plus keyword matches). Recordings without such a command never read your task list.
- Uploads are protected by a single bearer token compared with `hash_equals`; the endpoint fails closed when no token is configured. There are no accounts and no server-side way to read recordings back — the API only ever answers `202`/`4xx`.
- Recording history lives only in your browser (IndexedDB), from the moment recording stops (marked as not sent until you send it); the upload token is kept in `localStorage`.
- **Retention:** the daily `recordings:prune` command deletes audio files of *successfully processed* recordings after `RETENTION_DAYS`. Transcripts and typed notes remain in the SQLite database and in the created Vikunja tasks. Audio of *failed* recordings is kept until you retry or delete it manually — by design, so nothing is lost.

> [!WARNING]
> **ntfy privacy.** On the public `ntfy.sh` server, the topic name is the only thing protecting your notifications — anyone who knows or guesses it can subscribe (and publish). Notifications include the extracted task title, and failure notifications can include error details. If you use ntfy, prefer a self-hosted instance or an [access-protected topic](https://docs.ntfy.sh/publish/#access-tokens), or at minimum use a long random topic name (`openssl rand -hex 16`) and consider `NTFY_NOTIFY_SUCCESS=false`. Leaving `NTFY_TOPIC` empty disables notifications entirely.

## When something fails

- After 5 failed attempts the job lands in `failed_jobs` and a ntfy notification is sent (if configured). The audio stays on disk — failed recordings are never auto-deleted.
- Reprocess: `php artisan queue:retry all`
- Inspect: `php artisan queue:failed`
- **Uptime Kuma heartbeat:** with `KUMA_PUSH_URL` set, the scheduler queues a small job every 5 minutes and the worker pushes to Kuma, so a push arrives only while both run. It reports `down` while `failed_jobs` is not empty, and the time the job waited in the queue as the ping. Create a *Push* monitor with a heartbeat interval of about 15 minutes and paste its URL as is.
- Retries resume where they stopped: the Gemini extraction (and, for a voice command, which existing task it is about) is stored before the first task is created and every task ID right after its creation, so a retry never calls Gemini again and never re-creates an existing task. Only a worker dying between the Vikunja call and the DB write can exceptionally duplicate a single task — nothing is ever lost; a duplicate always beats a lost note.

## Contributing

Issues and pull requests are welcome at [AlexKratky/vikunja-voice](https://github.com/AlexKratky/vikunja-voice). This is a small personal tool — please keep changes focused.

## License

[MIT](LICENSE)
