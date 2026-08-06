# vikunja-voice

Hlasové zadávání úkolů do self-hosted [Vikunja](https://vikunja.io/). Nahraješ diktát v prohlížeči (PWA), server ho přes Gemini přepíše a vytěží (název, termín, priorita, projekt, štítky) a založí úkol přes Vikunja API. Žádná nahrávka se neztratí: soubor se uloží před zpracováním, job má 5 pokusů s backoffem a po selhání zůstává ve `failed_jobs`.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php scripts/make-icons.php
```

Vyplň v `.env`:

| Proměnná | Popis |
|---|---|
| `APP_UPLOAD_TOKEN` | dlouhý náhodný token pro upload (`openssl rand -hex 32`) |
| `GEMINI_API_KEY` | klíč z Google AI Studio |
| `GEMINI_MODEL` | default `gemini-2.5-flash` |
| `VIKUNJA_URL` | např. `https://vikunja.example.com` |
| `VIKUNJA_API_TOKEN` | Vikunja → Nastavení → API tokeny (práva: projects read, tasks write, labels read/write) |
| `VIKUNJA_DEFAULT_PROJECT_ID` | ID inbox projektu (fallback) |
| `NTFY_TOPIC` | ntfy topic pro notifikace (prázdné = vypnuto) |
| `RETENTION_DAYS` | po kolika dnech mazat zpracovaná audia (default 7) |

## Provoz

- Web: standardní Laravel deploy (nginx + php-fpm). Mikrofon vyžaduje **HTTPS**.
- Queue worker (systemd):

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

- Scheduler (cron): `* * * * * cd /var/www/vikunja-voice && php artisan schedule:run >> /dev/null 2>&1`

## Když něco selže

- Po 5 neúspěšných pokusech skončí job ve `failed_jobs` a přijde ntfy notifikace. Audio zůstává na disku (failed nahrávky se nikdy nemažou).
- Dozpracování: `php artisan queue:retry all`
- Přehled: `php artisan queue:failed`

## Bezpečnostní model

- Upload chráněn Bearer tokenem, porovnává se přes `hash_equals`.
- Server nemá žádný endpoint pro čtení, listování ani stahování nahrávek — jediné odpovědi jsou `202`/`4xx`.
- Historie nahrávek žije jen v prohlížeči (IndexedDB), token v `localStorage`.
