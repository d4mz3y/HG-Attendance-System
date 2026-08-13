# Hogan Guards Attendance

An internal attendance, scheduling, leave, and reporting system for Hogan Guards. The application uses Laravel 12, React 19, MySQL 8.4, and a production Docker image containing Nginx and PHP-FPM.

The supported production topology is one office server, trusted internal-CA HTTPS over explicit LAN/ZeroTier addresses, and encrypted external-drive backups. HTTPS is required for the offline kiosk service worker. See [DEPLOYMENT.md](DEPLOYMENT.md) for the complete certificate, build, firewall, backup, restore, Paystack, SMTP, and upgrade procedure.

## Requirements for local development

- PHP 8.4
- Composer 2
- Node.js 22 and npm
- MySQL 8.4, or SQLite for the automated test suite
- PHP extensions: `bcmath`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `simplexml`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, and `zip`

## Local setup

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan users:create-super-admin system.owner
php artisan users:upsert it.manager it_manager
npm run build
php artisan serve
```

`DatabaseSeeder` creates non-secret application defaults only. It does not create demonstration staff or predictable user accounts. First create the one super administrator with `users:create-super-admin`; it only works interactively when no super administrator exists. If upgrading an old installation that has a legacy `admin` account but no super administrator, give that account's username to the same command to promote and recover it. Create HR, HR assistant, and IT accounts with `users:upsert`; omit `--password` so the password is entered privately instead of being stored in shell history.

If the super administrator forgets their password, use the server's local terminal only (do not run it remotely through a browser shell):

```bash
php artisan users:recover-super-admin
```

The command uses the single existing super-admin account automatically; if there is more than one, pass its username as the final argument. It asks for confirmation and a new hidden password, revokes that account's sessions, and records the recovery. It never runs by itself and does not reset the database.

For frontend hot reload, run `npm run dev` in another terminal. Keep `APP_URL` aligned with the Laravel URL so signed links use the correct origin. Do not run `php artisan storage:link`: staff photos are intentionally private and are served only through short-lived signed URLs. If this project contains photos from an older public-storage version, run `php artisan staff:secure-photos` before local use and remove any existing `public/storage` symlink.

## Quality checks

```bash
composer validate --strict --no-check-publish
composer audit --locked
vendor/bin/pint --test
php artisan test
npm audit
npm run build
npm run check:pwa
```

CI repeats these checks, runs the feature suite against MySQL 8.4, builds the production Docker image, and smoke-tests its TLS endpoint, migrations, scheduler, and required PHP extensions.

## Operational commands

```bash
# List registered scheduled work
php artisan schedule:list

# Manually send the configured report for the last completed period
php artisan reports:send --force

# Send a deterministic report period during verification
php artisan reports:send --frequency=weekly --to=2026-08-09
```

Scheduled reports require a working SMTP configuration plus `enable_scheduled_reports`, `report_email`, and `report_frequency` in the Settings screen.

For a kiosk queue blocked by permanently rejected offline events, submit a recovery request from **Device queue**. IT must review and approve that exact device-signed request under **Scan devices**; the kiosk then removes only the approved blocked event IDs and re-signs/resequences what remains. Do not clear browser storage or approve a request without investigating the failures. The full procedure is in [DEPLOYMENT.md](DEPLOYMENT.md).

## Security rules

- Never commit `.env`, `.env.docker`, API keys, passwords, private backup identities, or production database exports.
- Never run a production seeder to create users.
- Never publish MySQL or the attendance web port through the internet router.
- Production clients must use the trusted HTTPS origin. ZeroTier transport encryption alone does not make a plain HTTP page a browser secure context.
- Treat staff photos, attendance, leave, audit logs, reports, and backups as confidential employee data.
- Rotate any secret that was previously stored in a committed environment file.

## Persistent data

The production Compose stack stores MySQL in `mysql_data` and private application files/runtime data in `app_storage`; disposable Laravel bootstrap caches live in memory. `docker compose down` preserves the data volumes. Do not use `docker compose down -v` on a system containing real data.
