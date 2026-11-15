# Lost & Found Web Application

A complete, production-ready Lost & Found platform built with PHP (PDO),
MySQL, vanilla JS (ES6+/Fetch), and modern responsive CSS.

## Features

- Dual authentication & dashboards (User / Admin) with role-based access
- Separate Lost & Found reporting flows — mandatory date, time, specific
  location, category, description, image upload, contact email & phone
- Smart AJAX search & filters (keyword, category, date range, location)
  plus a quick single-item lookup modal
- Manageable list views with select-all checkboxes and bulk delete
- Claim & verification system with secret-detail or document proof;
  owner contact info stays masked until a claim is approved
- Two-step report claim handshake: an admin claims an approved report first,
  which unlocks the reporter's own **Claim** button, which in turn unlocks
  **Mark Resolved**. Enforced in `api/claim_report.php` and
  `api/update_status.php`, not just in the UI
- Per-account theme preference (default / dark / light) on both the user and
  admin profile; `default` follows the visitor's OS setting
- Secure in-app chat between finder and claimant (unlocks after approval)
- Admin panel: approval queue, claim requests, item/user management,
  dashboard metrics, monthly activity trend chart, activity log
- CSV / Excel / PDF export — both client-side (`js/export.js`) and
  server-side (`exports/export_csv.php`, `exports/export_pdf.php`)
- Pluggable storage: local disk, Cloudinary, or AWS S3

## Requirements

- PHP 8.0+ with extensions: `pdo_mysql`, `mbstring`, `curl`, `gd`/`fileinfo`
- MySQL 5.7+ / MariaDB 10.3+
- A web server (Apache/Nginx) or `php -S` for local testing

## Setup

1. **Start MySQL / MariaDB** (XAMPP: press *Start* next to *MySQL*).

2. **Open the site.** That's it for the database: on first run the app
   creates the `lost_and_found` database, all tables and the default admin by
   itself (`includes/db_setup.php`), and upgrades older databases
   automatically. Prefer doing it by hand? Import `db_schema.sql` (it creates
   the database too):
   ```bash
   mysql -u root -p < db_schema.sql
   ```
   Default admin: `admin@lostfound.com` / `Admin@123` — **change it right
   after first login** (Admin Dashboard → My Profile).

   > **"Access denied for user 'root'"?** Open `http://localhost/lost-and-found/setup.php`,
   > enter the MySQL username/password you use for phpMyAdmin, and press
   > *Test & save*. It checks the login, creates the database and saves the
   > details to `config.local.php` (localhost only; delete `setup.php` before
   > going live).
   >
   > **Other "Database connection failed" errors?** On localhost the error shows the
   > real cause. Usually MySQL isn't running, or `DB_USER` / `DB_PASS` in
   > `config.php` don't match your MySQL login. The database name must be
   > `lost_and_found` (underscores) unless you change `DB_NAME`.

3. **Configure (only if needed).** Edit `config.php` or set environment
   variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`
   (auto-detected, so folder name/port no longer matter), `STORAGE_DRIVER`
   (`local`, `cloudinary`, `s3`), plus the Cloudinary / AWS S3 keys.
   `DB_AUTO_SETUP=0` turns off the automatic database setup.

4. **Run.** Put the folder in `htdocs` (XAMPP) and visit
   `http://localhost/lost-and-found/`, or for a quick test:
   ```bash
   php -S localhost:8000
   ```

## Updating to a newer zip (your DB login is kept)

`setup.php` saves your MySQL login in `config.local.php` **and** in
`lostfound.db.php` next to the project folder (e.g. `htdocs/lostfound.db.php`).
Replacing the project folder with a new zip deletes the first file, but the
second survives, so you don't get "Access denied for user 'root'" again.
If you ever do, just open `setup.php` once more.

## What's new in this version

- **Database error fixed** — database name mismatch, missing `CREATE DATABASE`,
  and automatic first-run setup / upgrade.
- **Themes** — one **Theme** dropdown in the header for everyone (visitors,
  users, admins): System default / Light / Dark. Saved to the account when
  logged in, to a cookie otherwise. Flat, clean background, no gradients.
- **No more stale files** — CSS/JS links carry a version (`?v=<file date>`),
  so after an update the browser can't keep running an old `main.js` against
  the new pages (that made buttons like *Open / Reply* do nothing).
- **Strong passwords** — min 8 characters with upper + lower case, a number
  and a symbol (e.g. `Abcd~!@#$%^&*()_123456789`), a live strength meter, and
  a "Confirm password" field that blocks copy / cut / paste / drag-drop.
  Enforced on the server too, on sign-up and in both profile pages.
- **Contact the admin** (`contact.php`) — works for guests too (so a blocked
  user can appeal). Admin → *Support Messages*: read, reply, set status
  (New / In progress / Resolved), delete, or block the sender. Users see the
  reply on the Contact page.
- **Delete reports** — a *Delete* button in My Dashboard → My Reports, the
  *My List View* on the Lost / Found pages, on your own grid cards, and in the
  admin *Manage Items* table. Removes claims, chats and image files too.
- **Block users** — Admin → *Manage Users*: Block (with a reason shown to the
  user) / Unblock, search and filter, report and claim counts. Blocking signs
  the user out on their next click, stops logins and hides their listings.
  Admin accounts can't be blocked or deleted from the panel.
- **Bug fixes** — see the list at the end of this file.

## Project Structure

```
lost-and-found/
├── index.php              Landing page (hero, quick search, recent items)
├── login.php               User/Admin login
├── register.php             User registration
├── logout.php
├── user_dashboard.php       My reports, claims, received claims, settings
├── admin_dashboard.php      Metrics, approval queue, claims, item/user mgmt
├── lost_items.php           Browse lost items (grid + manage list)
├── found_items.php          Browse found items (grid + manage list + claim)
├── report_lost.php          Report a lost item
├── report_found.php         Report a found item
├── about.php                About & FAQ
├── contact.php              Contact the admin (support messages)
├── config.php               DB connection + storage config
├── db_schema.sql            MySQL schema + seed admin
├── includes/
│   ├── auth.php              Session/role guards, login logic, blocked-user check
│   ├── db_setup.php          First-run database setup + automatic upgrades
│   ├── functions.php         Helpers: escaping, uploads, masking, CSRF
│   ├── header.php / footer.php
├── api/                     JSON AJAX endpoints (search, claims, chat, admin actions)
├── migrations/              Incremental SQL migrations for existing databases
├── exports/                 Server-side CSV & printable PDF reports
├── css/style.css            Responsive dashboard UI
├── js/main.js                Frontend interactivity
├── js/export.js              Client-side CSV/Excel/PDF export helpers
├── js/password.js            Password strength meter + no-paste confirm field
└── uploads/                  Local image storage (when STORAGE_DRIVER=local)
```

## Security Notes

- All forms use CSRF tokens (`csrfToken()` / `verifyCsrf()`).
- Passwords are hashed with `password_hash()` (bcrypt) and must meet the strength policy (`passwordPolicyErrors()`).
- Uploaded files get their extension from the verified file type (never the client's filename), and `uploads/.htaccess` forbids running scripts there.
- All database queries use PDO prepared statements.
- Public listings mask contact email/phone until a claim is approved.
- Change the default admin password before deploying to production.
- Set `display_errors` to `0` in production (already default in `config.php`).

## Tech Stack

- **Frontend:** HTML5, CSS3, vanilla JavaScript (Fetch API)
- **Backend:** PHP 8 + PDO
- **Database:** MySQL / MariaDB
- **Charts:** Chart.js (CDN)
- **Exports:** SheetJS + jsPDF (CDN, lazy-loaded) and native PHP

## Bugs fixed in this version

- Database name mismatch (`lost-and-found` vs `lost_and_found`) and a schema
  that never created the database ("No database selected").
- Uploads took the file extension from the user's filename, so a disguised
  `.php` file could be stored and executed. Now derived from the real type.
- Image links broke if the site's folder or port differed from `APP_URL`
  (a full URL was stored per image). Now built from the file name, and
  `APP_URL` is auto-detected.
- A blocked user stayed logged in and could keep using the site.
- An admin account could be suspended/deleted through the API.
- Chat messages could be sent before a claim was approved.
- Claims could be filed on unapproved/rejected items, with empty proof, or
  twice by the same person; an already-reviewed claim could be re-reviewed.
- No session-id regeneration at login (session fixation).
- `LIKE ... ESCAPE` used a backslash string that is fragile across
  MySQL/MariaDB; now uses `!`.
- Bulk delete left uploaded files behind on disk.

## Housekeeping note

The project root also contains unrelated practice scripts (`Factorial.php`,
`hello.php`, `sum.php`, `sumDigits.php`, `findMx.php`, `average.php`,
`prime or not.php`). They aren't part of the app and would be publicly
reachable if deployed — delete them before going live.
