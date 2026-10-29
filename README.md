# PDSync

Fill out the CSC Personal Data Sheet (CS Form 212, Revised 2026) with a live preview of the official form, then export it as PDF or as the official Excel file.

- **Without an account:** each PC/browser keeps its own PDS (HttpOnly cookie). There are no share links: a PDS is reachable only from that browser, or from the owner's Google account.
- **Signed in with Google (optional):**
  - Keep several PDS in **My PDS**: open, rename, delete, or **Copy to new**, which copies only the sections you choose.
  - Your PDS follows you to any device.

## Requirements

- PHP 8.0 or later, with these extensions: `pdo_mysql`, `curl`, `openssl`, `dom`, `zlib`.
- MySQL 5.7 or later, or MariaDB 10.2 or later.

## Deploy on cPanel

1. **Database:**
   1. In **MySQL Databases**, create a database and a user, then add the user to the database with ALL PRIVILEGES.
   2. In **phpMyAdmin**, select the database, then **Import** `sql/schema.sql`.
2. **Files:**
   1. Upload the project to a folder outside `public_html`, for example `~/pdsync`.
   2. Point the domain or subdomain's **Document Root** to `~/pdsync/public` (Domains > Manage).
   3. Only `public/` is web-facing; `lib/`, `data/`, `sql/` and `config.local.php` are not.
3. **Google sign-in:**
   1. In Google Cloud Console, go to APIs & Services > Credentials and create an **OAuth client ID** of type Web application.
   2. Add the authorized redirect URI `https://<your-domain>/auth.php`.
   3. Configure the OAuth consent screen with the scopes openid, email and profile, then publish it.
4. **Config:**
   1. Copy `config.sample.php` to `config.local.php` in the project root, outside `public/`.
   2. Fill in `APP_URL`, the database settings and the Google client ID and secret.
   3. Never commit or share this file.
5. **Final checks:**
   1. In **Select PHP Version**, use PHP 8.1 or later and enable the extensions listed above.
   2. Turn on **SSL/TLS** (AutoSSL); `public/.htaccess` redirects every request to HTTPS.
   3. Make sure `data/` is writable, since sign-in sessions are stored in `data/sessions`.
6. If you used the app before MySQL, copy those PDS records into the database:

   ```sh
   php tools/migrate-json.php
   ```

   The old `data/*.json` files are left in place. Delete them once you have checked the import.

If you leave Google settings empty, the app runs without the Sign in button.

## Admin

The admin page is `/admin.php`. Admin accounts are stored in `data/admins.json`, outside the web root and never committed. Manage them from a terminal:

```sh
php tools/admin-user.php add <username>     # asks for the password twice (min 12 characters), input hidden
php tools/admin-user.php passwd <username>
php tools/admin-user.php remove <username>
php tools/admin-user.php list
```

Security measures:

- Passwords are stored as bcrypt hashes.
- 5 failed sign-ins from one IP lock sign-in for 15 minutes.
- Sign-in uses a CSRF token and an Origin check.
- The admin session is separate from users' sessions:
  - Its cookie is HttpOnly and SameSite=Strict.
  - Its ID changes at each sign-in.
  - It expires after 30 minutes idle, or 8 hours after sign-in.
- The response time is the same for unknown and real usernames.

## Run locally

```powershell
.\tools\setup-local.ps1   # once: creates the local database and config.local.php
.\tools\serve.ps1         # starts http://localhost:8000
```

- `setup-local.ps1` asks for your MySQL root password, which it does not save. It creates the database and user and writes `config.local.php`.
- `serve.ps1` turns on the PHP extensions the app needs, so you don't need a `php.ini`.
- For Google sign-in on your PC, add `http://localhost:8000/auth.php` as a redirect URI and put the client ID and secret in `config.local.php`.

## Styling

The UI uses Tailwind CSS v4.3.3 and daisyUI v5.7.50 through the standalone CLI, so it needs no npm. The built `public/app.css` is committed, so servers need no build step. After changing classes or `tools/css/app.css`, rebuild it:

```powershell
.	oolsuild-css.ps1          # add -Watch to rebuild on every change
```

- The script downloads the pinned CLI and plugin into `tools/bin/` (not committed) and verifies their SHA-256 hashes.
- The official-form preview has its own `public/preview.css` and does not use Tailwind.

## Project layout

| Path | Contents |
|---|---|
| `public/` | Web root: pages, API endpoints, `app.js`, built `app.css`, `preview.css` (official-form look) and the form page images (`pds/`) |
| `tools/css/app.css` | Tailwind + daisyUI source for `public/app.css` |
| `lib/` | Storage, sign-in, xlsx reader/writer, PDS cell map, `schema.json` (form fields) and the official template |
| `sql/schema.sql` | Database tables |
| `tools/` | Local setup, local server, the JSON-to-MySQL migration, and the layout builder for new form revisions |
| `data/` | Sign-in sessions; not committed |

## Privacy and security

- Only the Google ID, email and name are stored for an account; Google tokens are never kept.
- Login uses the OAuth code flow with PKCE and `state`, and the ID token's issuer, audience, expiry and verified email are checked.
- Anonymous PDS:
  - They are reached only with this browser's HttpOnly cookie. The key never reaches page scripts, and the server stores only its SHA-256 hash.
- There are no public view or edit links. `view.php` only renders the posted draft for the live preview.
  - Signing in moves this browser's anonymous PDS into the account. The anonymous cookie then stops working for it.
  - Anonymous PDS not updated for 180 days are deleted.
- Signing out clears the account's PDS from the browser's storage.
- State-changing requests from other sites are rejected (Origin check plus SameSite cookies).

## New form revisions (for example, Revised 2027)

Data is stored by field key, not by Excel cell. When CSC releases a new form:

1. Add the new template to `lib/templates/` and update the cell map in `lib/pds-xlsx.php`.
2. Update `lib/schema.json` for new or changed fields.
3. Regenerate the preview pages:

   ```powershell
   .\tools\build-layout.ps1
   ```

4. Change `FORM_ID` in `lib/store.php`.

Fields whose keys are unchanged carry over automatically. Signed-in users can use **Copy to new** to start a new-form PDS from an old one.

## Official Excel and preview

- Export fills the official CSC template (`lib/templates/pds-2026.xlsx`, sheets C1-C4), including checkboxes, the country dropdown, the signature, the photo and the thumbmark.
- **Import Excel** reads a filled official PDS back in.
- The preview and printout overlay your answers on images of the official pages. `tools/build-layout.ps1` regenerates those images and positions; it needs Excel on Windows.
