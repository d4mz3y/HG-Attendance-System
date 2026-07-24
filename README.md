# Hogan Guards HQ — Attendance Management System

A staff attendance management portal built with Laravel (backend) and React (frontend). Supports clock-in/clock-out via barcode scanning, public holidays, schedules, attendance reports, manual override, and scan history.

## Stack

- **Backend**: PHP 8.2+, Laravel 11, MySQL, Sanctum (API auth)
- **Frontend**: React 18, Vite, Tailwind CSS, Axios
- **Scans**: Barcode input via keyboard wedge (the Scan page captures barcode input
  through a hidden `<input>` element and processes the value via `POST /api/scan`)

## Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, **`gd`** (staff photo validation), `zip` (for Excel / PhpSpreadsheet)
- MySQL 8.0+
- Composer
- Node.js 18+
- npm or yarn

## Setup

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan serve
```

Then run the frontend dev server:

```bash
npm install
npm run dev
```

## Login

- **superadmin** / `super123` (full access including manual override, staff CRUD, settings, public holidays)
- **admin** / `admin123` (attendance log, reports, schedules, limited staff management)

## Key endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/api/login` | No | Authenticate and receive Sanctum token |
| POST | `/api/scan` | No | Process a staff barcode scan (clock-in or clock-out) |
| POST | `/api/biometric/punch` | No | Process a biometric punch |
| GET | `/api/staff` | Yes | List staff with search/filter |
| GET | `/api/staff/{staff}` | Yes | Single staff record |
| GET | `/api/attendances` | Yes | Attendance log with filters |
| PATCH | `/api/attendances/{attendance}` | Yes | Edit clock-in/clock-out (super_admin only) |
| POST | `/api/attendances/manual` | Yes | Manual attendance override (super_admin only) |
| GET | `/api/reports` | Yes | Attendance report rows |
| GET | `/api/scan/pending` | Yes | Offline sync queue count |
| POST | `/api/scan/sync` | Yes | Sync offline scans |
| GET | `/api/settings` | Yes | System settings (super_admin only) |
| PUT | `/api/settings` | Yes | Update settings (super_admin only) |
| GET | `/api/public-holidays` | Yes | List public holidays |
| POST | `/api/public-holidays` | Yes | Create public holiday (super_admin only) |
| PUT/DELETE | `/api/public-holidays/{id}` | Yes | Update/delete (super_admin only) |

## Staff IDs

Staff IDs use the format `{COMPANY}/{BRANCH}/{DEPT}/{NNN}` (e.g. `HGL/LA/OPS/001`). The format is validated on the backend and regenerated per-department via `/api/staff/next-id`. The Scan page sends the raw staff ID string to `/api/scan` for clock-in/clock-out matching.