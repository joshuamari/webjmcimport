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

The dev server listens on port 3000. With `basePath` set (see below), the page is **http://localhost:3000/jmcimport**. The usual way to open it is through Apache: **http://localhost/jmcimport**.

Example `.env.local`:

```env
NEXT_PUBLIC_API_BASE=http://localhost/webJmcImport/api
NEXT_PUBLIC_KDT_LOGIN_URL=http://localhost/KDTPortalLogin/
NEXT_PUBLIC_WEBJMR_JMC_URL=http://localhost/webJMR/JMC
```

The Next app calls Laravel **directly** (`NEXT_PUBLIC_API_BASE`) with credentials so the shared `userID` cookie works. CORS must allow every origin you open the UI from (`http://localhost:3000` and `http://localhost`).

### Production-ish frontend build

```bash
cd frontend
npm run build
npm start
```

`npm start` still listens on port 3000. The Apache proxy below works the same way for that process.

---

## 3. Serve the UI at `/jmcimport` (Apache reverse proxy)

Next.js cannot be served as plain files. Node has to stay running. Apache only forwards `http://localhost/jmcimport` to `http://127.0.0.1:3000/jmcimport`.

The Laravel API is separate and is **not** proxied. It stays at `http://localhost/webJmcImport/api`, served from `backend/public`.

Through the proxy, the page and the API are both `http://localhost`, so the browser sends the shared `userID` cookie on API calls.

### 3.1 Tell Next.js it lives under `/jmcimport`

`frontend/next.config.ts`:

```ts
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  basePath: "/jmcimport",
  cacheComponents: true,
  partialPrefetching: true,
};

export default nextConfig;
```

Restart `npm run dev` after changing `basePath`. Pages, `/_next` assets, and client navigation then use `/jmcimport`. `http://localhost:3000/` returns 404 on purpose; open `/jmcimport`.

### 3.2 Turn on the Apache proxy modules

In `C:\xampp\apache\conf\httpd.conf`, these lines must be uncommented:

```apache
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule proxy_wstunnel_module modules/mod_proxy_wstunnel.so
```

The vhost file must be included (also in `httpd.conf`):

```apache
Include conf/extra/httpd-vhosts.conf
```

`ProxyPass` does not work from `.htaccess`. It has to be in this server config.

### 3.3 Proxy `/jmcimport` in the virtual host

Inside the `<VirtualHost *:80>` block in `C:\xampp\apache\conf\extra\httpd-vhosts.conf`. The WebSocket rule must be listed **before** the general `/jmcimport` rules, or hot reload is sent to Next.js as a normal request and returns 404.

```apache
# Next.js dev overlay requests this font from the site root, ignoring basePath.
ProxyPass /__nextjs_font http://127.0.0.1:3000/__nextjs_font
ProxyPassReverse /__nextjs_font http://127.0.0.1:3000/__nextjs_font

# Hot reload socket. Must be listed before the HTTP ProxyPass for /jmcimport.
ProxyPass /jmcimport/_next/hmr ws://127.0.0.1:3000/jmcimport/_next/hmr
ProxyPassReverse /jmcimport/_next/hmr ws://127.0.0.1:3000/jmcimport/_next/hmr

ProxyPass /jmcimport http://127.0.0.1:3000/jmcimport
ProxyPassReverse /jmcimport http://127.0.0.1:3000/jmcimport

ProxyPass /jmcimport/ http://127.0.0.1:3000/jmcimport/
ProxyPassReverse /jmcimport/ http://127.0.0.1:3000/jmcimport/
```

`/__nextjs_font` and the `/_next/hmr` socket are only needed while `npm run dev` is running.

### 3.4 Restart and open it

1. Leave `npm run dev` running in `frontend/`.
2. In the XAMPP control panel, **Stop** then **Start** Apache. A config change is not picked up until Apache restarts.
3. Open **http://localhost/jmcimport**.

Quick check from a terminal:

```bash
curl -sI http://localhost/jmcimport
```

You should get a redirect or `200` from Next.js, not an Apache 404.

---

## 4. How to use (day-to-day)

1. **Start MySQL + Apache** (XAMPP).
2. Confirm API: `http://localhost/webJmcImport/api/health`
3. Start the UI: `cd frontend && npm run dev`
4. Open **http://localhost/jmcimport**
5. **Sign in** via the shared KDT / Web JMR login if prompted  
   (this app does **not** have its own login/logout page)
6. On the import screen:
   - Download the Excel template, or use an existing drawing list
   - Choose your **Group**
   - Select a **`.xlsx`** file and click **Validate & Preview**
   - Confirm the import when the preview shows the new rows
7. New rows are inserted into Web JMR. Existing JMC rows are left unchanged.
   Daily Report reads `projectstable`, `itemofworkstable`, and `drawingreference` directly, so a new active row is available there after the import commits.

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
| `GET` | `/jmc/template` | cookie + import permission | Download a blank `.xlsx` template |
| `POST` | `/jmc/preview` | cookie + import permission | Validate `.xlsx` + `group` and return a preview |
| `GET` | `/jmc/preview/{token}` | cookie + import permission | Paged preview (`filter`, `q`, `page`) |
| `GET` | `/jmc/preview/{token}/errors` | cookie + import permission | Excel error report |
| `POST` | `/jmc/preview/{token}/confirm` | cookie + import permission | Insert new rows only |
| `GET` | `/jmc/history` | cookie + import permission | Import history for the user's groups |
| `POST` | `/jmc/import` | cookie + import permission | Legacy one-step import used by the plain HTML page |

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
| `http://localhost/jmcimport` is 404 | `npm run dev` running; proxy rules in `httpd-vhosts.conf`; Apache restarted after the edit |
| Hot reload `ws://localhost/jmcimport/_next/hmr` 404 | `proxy_wstunnel_module` loaded; WebSocket `ProxyPass` listed above the `/jmcimport` rule; Apache restarted |
| `/__nextjs_font` 404 in the dev overlay | `ProxyPass /__nextjs_font` in the same virtual host (dev only) |
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
