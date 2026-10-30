# Web JMC Import

Browser-based **JMC drawing-list Excel importer** for Web JMR.

| Piece | Stack | Folder |
|-------|--------|--------|
| API | Laravel + PhpSpreadsheet | `backend/` |
| UI | Next.js | `frontend/` |
| Auth | Shared KDT cookie `userID` (from `kdtphdb.kdtlogin.fldUserHash`) | — |

This repo is the **web** app only. The old Windows Forms tool lives separately and is not included.

---

## Requirements

- **PHP** 8.3+ and **Composer** 2.x
- **Node.js** 20+ and **npm**
- **MySQL** with access to:
  - `kdtphdb` (login / permissions)
  - `webjmrdb` (projects, item of works, drawing reference)
- Local stack: **XAMPP** (Apache + MySQL) is the usual setup

---

## 1. Backend setup (Laravel)

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
```

Edit `backend/.env` and set at least:

```env
APP_URL=http://localhost/webJmcImport

DB_KDTPH_HOST=127.0.0.1
DB_KDTPH_DATABASE=kdtphdb
DB_KDTPH_USERNAME=...
DB_KDTPH_PASSWORD=...

DB_WEBJMR_HOST=127.0.0.1
DB_WEBJMR_DATABASE=webjmrdb
DB_WEBJMR_USERNAME=...
DB_WEBJMR_PASSWORD=...

KDT_LOGIN_URL=http://localhost/KDTPortalLogin/
WEBJMR_JMC_URL=http://localhost/webJMR/JMC

CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
```

### Point Apache at the API (XAMPP)

Serve `backend/public` under something like:

`http://localhost/webJmcImport/`

(junction / alias / virtual host — whatever you already use locally.)

Quick health check:

```text
GET http://localhost/webJmcImport/api/health
```

You should get a JSON OK / DB ping response.

---

## 2. Frontend setup (Next.js)

```bash
cd frontend
cp .env.local.example .env.local
npm install
npm run dev
```

Open: **http://localhost:3000**

Example `.env.local`:

```env
NEXT_PUBLIC_API_BASE=http://localhost/webJmcImport/api
NEXT_PUBLIC_KDT_LOGIN_URL=http://localhost/KDTPortalLogin/
NEXT_PUBLIC_WEBJMR_JMC_URL=http://localhost/webJMR/JMC
```

The Next app calls Laravel **directly** (`NEXT_PUBLIC_API_BASE`) with credentials so the shared `userID` cookie works. CORS must allow `http://localhost:3000`.

### Production-ish frontend build

```bash
cd frontend
npm run build
npm start
```

---

## 3. How to use (day-to-day)

1. **Start MySQL + Apache** (XAMPP).
2. Confirm API: `http://localhost/webJmcImport/api/health`
3. Start the UI: `cd frontend && npm run dev`
4. Open **http://localhost:3000**
5. **Sign in** via the shared KDT / Web JMR login if prompted  
   (this app does **not** have its own login/logout page)
6. On the import screen:
   - Choose your **Group**
   - Select a **`.xlsx`** drawing-list file
   - Click **Import**
7. Watch the log panel for success / errors  
   On success, Web JMR JMC may open in a new tab (`WEBJMR_JMC_URL`)

### Who can import?

- Cookie `userID` must resolve to a valid KDT user
- User must have **JMC Import** permission (`user_permissions.permission_id = 2`)
- Selected group must be one of the user’s allowed groups

### What the Excel import does

- Reads each sheet as a project (some sheet names are ignored, same rules as the old WinForms app)
- Validates headers (including optional **KHI Prep** column; CM → CEM mapping)
- Writes in order:
  1. `projectstable`
  2. `itemofworkstable`
  3. `drawingreference`

---

## API reference

Base URL (local XAMPP): `http://localhost/webJmcImport/api`

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/health` | none | Health / DB ping |
| `GET` | `/me` | `userID` cookie | Current user, groups, `canImport` |
| `POST` | `/jmc/import` | cookie + import permission | Upload `.xlsx` + `group` |

`POST /jmc/import` form fields:

- `group` — string (required)
- `file` — `.xlsx` file (required)

---

## Optional: legacy plain HTML UI

If you still need the older non-Next UI:

- XAMPP: `http://localhost/webJmcImport/app/`
- Local backup sources: `frontend-plain-backup/` (not part of the main workflow)

---

## Troubleshooting

| Symptom | What to check |
|---------|----------------|
| Redirected to login | `userID` cookie missing / wrong domain; open KDT login first |
| Access denied | Permission `permission_id = 2` not granted |
| CORS / network error from :3000 | `CORS_ALLOWED_ORIGINS` and `NEXT_PUBLIC_API_BASE` |
| API 500 on import | MySQL credentials; Excel format / headers; Laravel `storage/logs` |
| Health OK but `/me` fails | Cookie present but user hash not found in `kdtlogin` |

---

## Project layout

```text
webJmcImport/
├── backend/          Laravel API
├── frontend/         Next.js UI
├── frontend-plain-backup/   old plain HTML/JS UI (backup only)
└── README.md
```
