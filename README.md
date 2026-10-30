# Web JMC Import

Browser-based JMC drawing-list Excel importer for Web JMR.

- **Backend:** Laravel REST API (`backend/`)
- **Frontend:** Next.js (`frontend/`) calling Laravel directly
- **Auth:** Shared KDT cookie `userID` (user hash from `kdtphdb.kdtlogin.fldUserHash`)
- **Reference logic:** `D:\joshua\webJmrJmcImport` (WinForms)
- **Legacy plain UI backup:** `frontend-plain-backup/` (and XAMPP `public/app/` still works)

## Requirements

- PHP 8.2+ (8.5 OK)
- Composer 2.x
- MySQL access to `kdtphdb` and `webjmrdb`

## Setup

```bash
cd backend
cp .env.example .env
php artisan key:generate
# edit .env: DB_KDTPH_*, DB_WEBJMR_*, KDT_LOGIN_URL, WEBJMR_JMC_URL
composer install
```

### Local: Laravel on XAMPP + Next.js on :3000

1. Start **Apache** + **MySQL** in XAMPP  
2. API (already junctioned): http://localhost/webJmcImport/api/health  
3. Frontend:

```bash
cd frontend
cp .env.local.example .env.local   # if needed
npm install
npm run dev
```

Open http://localhost:3000  

Next.js calls Laravel directly (`NEXT_PUBLIC_API_BASE`). CORS allows `http://localhost:3000`.

Optional legacy plain UI: http://localhost/webJmcImport/app/

## API

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/api/health` | no | Health / DB ping |
| GET | `/api/me` | `userID` cookie | Current user + groups + `canImport` |
| POST | `/api/jmc/import` | `userID` cookie + permission | Upload `.xlsx` + `group` |

## Notes

- This app does **not** implement login/logout; use the shared KDT/Web JMR login.
- JMC Import requires `user_permissions.permission_id = 2`.
- Import write order: `projectstable` → `itemofworkstable` → `drawingreference`.
- Excel logic is ported from `D:\joshua\webJmrJmcImport\jmc.cs` (sheet ignore list, header validation, optional KHI Prep column, CM→CEM).

## Import flow

1. Open `frontend/index.html` (same site/cookie domain as Web JMR in production).
2. `GET /api/me` resolves cookie `userID`.
3. Upload `.xlsx` + group → `POST /api/jmc/import`.
