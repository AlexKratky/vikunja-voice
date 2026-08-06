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
| `VIKUNJA_AUTO_ASSIGN_USERNAME` | uživatel, kterému se každý úkol přiřadí (prázdné = nepřiřazovat) |
| `VIKUNJA_AUTO_LABEL` | název štítku, který se přidá ke každému úkolu (prázdné = vypnuto) |
| `NTFY_TOPIC` | ntfy topic pro notifikace (prázdné = vypnuto) |
| `NTFY_URL` | ntfy server, default `https://ntfy.sh` |
| `NTFY_NOTIFY_SUCCESS` | posílat notifikaci i při úspěchu, default `true` |
| `RETENTION_DAYS` | po kolika dnech mazat zpracovaná audia (default 7) |
| `VOICE_TIMEZONE` | časová zóna pro parsování termínů, default `Europe/Prague` |

Na VPS v produkci nastav v `.env` navíc `APP_ENV=production` a `APP_DEBUG=false` — s `APP_DEBUG=true` uniká stack trace (včetně API klíčů v `.env`) do chybové stránky.

## Provoz

Mikrofon vyžaduje **HTTPS** (v obou variantách níže).

### Coolify (Railpack build pack)

Railpack pozná Laravel automaticky (composer install, `npm run build`, migrace při startu) a servíruje appku přes FrankenPHP/Caddy. Repo obsahuje `start-container.sh`, který navíc spouští queue worker a scheduler ve stejném kontejneru — v Coolify tedy stačí jedna aplikace, žádný zvláštní worker.

1. Nová aplikace z git repa, **Build Pack: Railpack**.
2. **Persistent Storage**: volume mount na `/app/storage` (nahrávky + SQLite musí přežít redeploy).
3. **Environment Variables**: vše z tabulky výše a navíc:
   - `APP_KEY` — vygeneruj lokálně `php artisan key:generate --show`
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`
   - `DB_CONNECTION=sqlite`, `DB_DATABASE=/app/storage/database.sqlite` — absolutní cesta na volume; výchozí `database/database.sqlite` by se při redeployi smazala
   - `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `DB_QUEUE_RETRY_AFTER=330`
4. Nastav doménu — HTTPS řeší Coolify proxy. Health check můžeš namířit na `/up`.

### VPS (nginx + php-fpm)

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
- `DB_QUEUE_RETRY_AFTER` (v `.env`) musí zůstat vyšší než timeout jobu (`--timeout=300` výše), jinak si ho při druhém workerovi „ukradnou" a zpracuje se duplicitně.

## Když něco selže

- Po 5 neúspěšných pokusech skončí job ve `failed_jobs` a přijde ntfy notifikace. Audio zůstává na disku (failed nahrávky se nikdy nemažou).
- Dozpracování: `php artisan queue:retry all`
- Přehled: `php artisan queue:failed`
- Zpracování je at-least-once: pokud worker spadne těsně po založení úkolu ve Vikunja, ale před zápisem do DB, retry může výjimečně založit duplicitní úkol — nic se ale nikdy neztratí, duplicita je vždy lepší než ztráta.

## Bezpečnostní model

- Upload chráněn Bearer tokenem, porovnává se přes `hash_equals`.
- Server nemá žádný endpoint pro čtení, listování ani stahování nahrávek — jediné odpovědi jsou `202`/`4xx`.
- Historie nahrávek žije jen v prohlížeči (IndexedDB), token v `localStorage`.
