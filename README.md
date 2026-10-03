# Civil Registration System — Mogadishu, Banadir Region

Digital Assistant System for Civil Registration Record-Keeping: birth, death, marriage and divorce records with approval workflow, certificates with QR verification, fees, reports and audit logging.

**Stack:** HTML5, CSS3, JavaScript, PHP 8.1+, MySQL 8 (or MariaDB 10.4+), Apache (XAMPP).
**Status:** All 12 phases are complete. The test suite holds 543 checks over every service. See the roadmap below.

## Quick start (XAMPP)
1. Copy this folder to `C:\xampp\htdocs\civil-registration`.
2. Start Apache and MySQL. In phpMyAdmin (Import tab) run `database/schema.sql`, then `database/seed.sql`. The seed grants the administrator the whole permission catalogue.
3. From the project folder run: `php database/seed.php` (creates sample users, one sample certificate).
4. Open `http://localhost/civil-registration/public/`.
5. If you use another folder name or a virtual host, set `base_url` in `config/app.local.php`. If MySQL has a password, set it in `config/database.local.php`.

Sample users (development only, password `ChangeMe#2026`, must be changed at first login): `nasra` (administrator), `abdi` (supervisor), `faduma` and `mahad` (officers).
Verify page test token: `a91f3c7e52b04d6f8e21`.
Run the tests: `php tests/run.php` (add a test-only account or record and the suite still passes twice in a row). The suite borrows the four sample accounts, so it signs them in with the password above no matter what the database currently holds, and puts their original rows back when it finishes.

## Folder structure
```
civil-registration/
├─ public/                  Only this folder is web-facing (Apache document root in production)
│  ├─ index.php, about.php, contact.php, login.php, logout.php,
│  │  change-password.php, verify.php, dashboard.php
│  ├─ admin/ officer/ supervisor/     Role pages (each starts with Auth::requireRole)
│  └─ assets/ css/ js/ img/
├─ app/                     Application code (never web-accessible)
│  ├─ bootstrap.php         Autoloader, error handling, session
│  ├─ Core/                 Config, Database (PDO), Session, Csrf, Auth, Audit, Logger
│  ├─ Services/             Business logic: Settings, Stats, CertificateVerifier (more per phase)
│  ├─ Support/              helpers.php, Validator, Age, RecordTypes
│  └─ Views/                layouts/, partials/, errors/
├─ config/                  app.php, database.php, navigation.php (+ *.local.php overrides)
├─ database/                schema.sql, seed.sql, seed.php
├─ storage/                 uploads/ backups/ logs/  (private, not served directly)
├─ tests/                   run.php (runner) + suites/ (10_core_support, 15_records_listing,
│                           20_users_roles, 25_profile_security, 30_settings_notifications,
│                           40_reports_stats, 50_backup_export, 60_certificates_payments,
│                           70_session_hardening)
└─ docs/                    architecture notes
```

## Layers (each page follows the same path)
`Browser → public/*.php → Auth (login, role, permission) → Validator → Service → Database (PDO prepared statements) → MySQL`, with `Audit` and notifications written on every important action.

## Roles
Three roles: administrator, officer, supervisor. Every menu entry and every page is filtered by permission, not by role, so a grant or a revoke takes effect on the account's next click with no "sign in again to see it" step. The **administrator is the superuser** and reaches every desk, which is why the role screen (`admin/roles.php`) shows its boxes ticked and locked: `RoleService::setPermissions()` tops the role back up to the whole catalogue rather than accepting a narrower set, and a permission added later is granted to it as it is created.

## Record listings
`RecordService::listRecords()` is the one query behind both listing screens, and each event type is a separate table, so it merges and sorts the four of them. An officer reaches the whole registry from **My Records → All Records** (`scope=all`), which adds a "Registered By" column; the manager has **All Records** in the sidebar (`records/all-records.php`, supervisor and administrator), which adds a registration-desk filter. Both keep the status tabs, a free-text search and an event-type filter. Only reading widens: **Edit** still appears only where `RecordService::canEdit()` allows it.

## Reports
`supervisor/reports.php` carries both levels of report. The headline tables mix the four events (districts, monthly volume, fees, officer activity), and the top of the page reports **one event at a time**: the event tabs and the `event` parameter choose birth, death, marriage or divorce, and that report shows its own totals, monthly volume, districts, workflow status and records, with four CSV exports of its own. The **All Events Register** at the foot of the page lists every civil record of the year in one table, with the second party named for a couple and the sex shown where the event records one. Every link is built with `url()`, because this page lives in `/supervisor/`: a bare `supervisor/reports.php` would resolve to `/supervisor/supervisor/reports.php`.

## Profile security
A member keeps their own username, password and photo on **My Profile** (`profile.php`), and every one of the three asks for the current password first, so a left-open session cannot take the account over. `UserService::changeOwnPassword()` and `changeOwnUsername()` are the only paths, and `change-password.php` calls the same method, so the first-sign-in page and the profile panel cannot drift apart. A photo is kept in `storage/uploads/avatars/` outside the web root, accepted as JPG, PNG, GIF or WEBP up to 2 MB and 3000 pixels, and shown through `avatar.php`, which streams only the signed-in member's own image. The sidebar and top bar fall back to the initials of the name.

## Conventions
- One class per file, namespace `App\...` matches the folder (PSR-4 style).
- Pages stay thin: guard, read input, call a service, render a view.
- Every POST form includes `Csrf::field()` and calls `Csrf::check()`.
- Every output is escaped with `e()`. Every SQL statement uses bound parameters.
- Every protected page begins with `Auth::requireRole(...)` or `Auth::requirePermission(...)`; buttons are never the only protection.
- Age is calculated (`Age::between`), never stored. Records are archived or revoked, never deleted.
- Technical errors go to `storage/logs`; users only see a friendly message.
- Tests live in `tests/suites/*.php` and are loaded by `tests/run.php` in filename order. Every check cleans up after itself, and the suite must give identical output on repeated runs.

## Roadmap (one phase at a time)
1 Structure and database ✔ · 2 Public site and authentication ✔ · 3 Dashboards ✔ · 4 Birth, death, marriage, divorce modules ✔ · 5 Search and record management ✔ · 6 Approval workflow ✔ · 7 Certificates, fees, QR ✔ · 8 Reports ✔ · 9 Audit log viewer and notifications ✔ · 10 Digital Assistant ✔ · 11 Backup and security hardening ✔ · 12 Testing and polish ✔.

## Before going live
Change all default passwords, set `debug` to `false`, serve over HTTPS, point the web root at `public/`, restrict database user rights, and schedule backups.
