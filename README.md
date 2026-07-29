# Hogan Guards HQ — Barcode attendance

Laravel 11 + React (Vite) + Tailwind CSS + MySQL. Public kiosk scan route at `/scan`; admin UI under `/dashboard` (Sanctum personal access tokens).

## Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, **`gd`** (staff photo validation, **QR code and Code 128 barcode PNG exports**, and PhpSpreadsheet-related rendering), `zip` (for Excel / PhpSpreadsheet)
- Composer 2
- Node.js 20+ and npm
- MySQL 8 (or compatible)

## Run with Docker (full stack)

If you have [Docker Engine](https://docs.docker.com/engine/install/) and Docker Compose v2:

```bash
cd "/path/to/HG Attendance System"
docker compose up --build
```

Then open:

- **App / kiosk:** [http://localhost:8000](http://localhost:8000) — scan terminal: [http://localhost:8000/scan](http://localhost:8000/scan)
- **MySQL** (optional host access): `127.0.0.1:3307` — database `hg_attendance`, user `root`, password `root`

The first boot runs `composer install`, `npm ci` + `npm run build`, migrations, and seeders inside the `app` container. Stop with `docker compose down` (add `-v` to drop the database volume).

## Local setup

1. **Clone / copy** the project and enter the directory.

2. **Install PHP dependencies**

```bash
composer install
```

This pulls `endroid/qr-code` and `picqer/php-barcode-generator` for staff code downloads; both PNG paths use PHP’s **GD** extension (`gd`).

3. **Environment**

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `APP_URL` (include port if you use `php artisan serve`, e.g. `http://127.0.0.1:8000`).

**Cache (scan debounce):** The kiosk uses `Cache::add()` for a 1-second anti-double-wedge lock and relies on your default cache store. `.env.example` sets `CACHE_STORE=database`, which requires the `cache` / `cache_locks` tables from migrations. For a single-server LAN install you may prefer `CACHE_STORE=file` (no extra DB tables). Redis is optional.

**Queue:** Default `QUEUE_CONNECTION=database` in `config/queue.php` expects the `jobs` tables from migrations. If you are not dispatching jobs, `QUEUE_CONNECTION=sync` in `.env` avoids queue worker setup.

4. **Database**

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
```

5. **Frontend**

```bash
npm install
npm run build
```

For local development with hot reload, run in two terminals:

```bash
php artisan serve
npm run dev
```

With `npm run dev`, set `APP_URL` to match the Laravel server URL so generated storage URLs resolve correctly.

## Default admin (seeded)

| Field    | Value       |
|----------|-------------|
| Username | `admin`     |
| Password | `admin123` |

Change the password immediately in production (update the `users` table or add a change-password flow later).

## Sample barcodes

Seeded staff IDs (encode these on ID cards or type them in the kiosk field):

- `HGL/LA/OPS/001`
- `HGL/LA/SEC/001`
- `HGL/LA/ADM/001`
- `HGL/LA/FIN/001`
- `HGL/LA/SEC/002`
- `HGL/LA/OPS/002`
- `HGL/LA/BOD/001`
- `HGL/LA/MGT/001`

## Behaviour summary

- **Scan (`POST /api/scan`)**: toggles clock-in / clock-out for today; applies shift rules for late minutes and overtime; debounces repeat scans per employee using `scan_debounce_seconds` (default 120s).
- **Excel export**: Maatwebsite Excel; filename pattern `HoganGuards_Attendance_[Department]_[DateRange].xlsx`.
- **Staff export**: CSV download from **Staff → Export CSV**.
- **Staff QR / barcode (admin, Sanctum)**: `GET /api/staff/{id}/codes/qr` and `GET /api/staff/{id}/codes/barcode` return PNG attachments. The encoded value is the public `staff_id` (e.g. `HGL/LA/OPS/001`), matching **`POST /api/scan`** input. The React staff list and staff form expose download actions that call these endpoints with the bearer token.

## Deployment notes (HQ LAN server)

- Prefer HTTPS on the LAN if possible; keep the kiosk machine on a trusted VLAN.
- Point the entrance monitor to `/scan` in full-screen browser (F11).
- Plug the USB scanner; it should type digits/letters and Enter — the hidden field captures input.
- Ensure Windows / Linux user session does not steal focus from the browser.

## Troubleshooting

- **Photos 404**: run `php artisan storage:link` and confirm `FILESYSTEM_DISK=public`.
- **Excel export fails**: install PHP `zip` extension; run `composer install` again.
- **403 on API after login**: confirm `Authorization: Bearer <token>` is sent (see browser devtools); token is cleared on logout.
- **Scan errors / slow after deploy**: ensure the cache store is writable (`storage/framework/cache` for `file`, or migrated tables for `database`). If `/up` health checks return HTML instead of “healthy”, confirm `routes/web.php` still excludes the `up` segment from the SPA catch-all.
