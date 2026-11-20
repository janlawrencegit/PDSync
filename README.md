# PDSync

[![CI](https://github.com/janlawrencegit/PDSync/actions/workflows/ci.yml/badge.svg)](https://github.com/janlawrencegit/PDSync/actions/workflows/ci.yml)

Fill out the CSC Personal Data Sheet (CS Form No. 212, Revised 2026) with a live preview of the official form, then export it as PDF or as the official Excel file.

- **Without an account:** each PC/browser keeps its own PDS (HttpOnly cookie). There are no share links: a PDS is reachable only from that browser, or from the owner's Google account.
- **Signed in with Google (optional):**
  - **My PDS** (`/library.php`) is a library of all your PDS, as a grid or list, with search, a status filter and sorting.
  - Each PDS has a menu (also on right-click): open, open in a new tab, rename, make a copy, copy chosen sections to a new PDS, download Excel or a JSON copy, and move to the Trash.
  - The Trash keeps deleted PDS for 30 days, with Restore and Delete forever.
  - Your PDS follows you to any device.

What helps people fill it out:

- **Guidance:** hints from the official form under each field, an N/A button per field, and **Fill empty with N/A** per section.
- **Checks:** gentle notes for values that look wrong, such as height in centimeters, a TIN with too few digits, or a "To" date before "From". They never block saving.
- **Progress:** each section shows a check mark when every item is answered and a half circle when started.
- **Check my PDS:** a readiness score that lists empty items and likely mistakes HR would send back, such as overlapping or future work dates, with a link to each. Export asks the person to confirm the form's declaration first.
- **Work Experience Sheet:** the separate CSC attachment, filled from the work entries into the official template, as PDF or Word.
- **Merge PDFs:** joins the PDS, the Work Experience Sheet and supporting documents into one PDF, in the browser.
- **Photo and signature help:** a crop guide for the passport photo, checks on its size and background, and a guide to which e-signatures are accepted.
- **Addresses:** province, city and barangay suggestions from the PSA's PSGC list.
- **Tables:** move entries up or down, drag to reorder, duplicate, sort newest first, mark a part as None (N/A), and choose Present for a current job.
- **Continuation sheets:** entries beyond the form's rows go on the official sheets C5-C11, in both the Excel file and the PDF.
- **Signature:** upload one or draw it on screen.
- **Safety nets:** autosave with a status indicator, undo for big changes, version history, a warning when the same PDS was changed in another tab or device, and downloadable backup copies.
- **Interactive preview:** click any box on the form to jump to that item, and the item you are typing is highlighted on the form. Zoom with the buttons, Ctrl + scroll or two fingers, drag to move around, or open it full screen.
- **Works anywhere:** a collapsible preview, a separate preview view on phones, dark mode, and offline loading (the app opens without a connection and saves when it is back).
- **Shared computers:** "Delete my PDS from this PC" removes it from the browser and the server. A privacy notice is at `/privacy.php`.

## Requirements

- PHP 8.0 or later, with these extensions: `pdo_mysql`, `curl`, `openssl`, `dom`, `zlib`.
- MySQL 5.7 or later, or MariaDB 10.2 or later.

## Deploy with Docker (recommended)

Any server with Docker, such as a small VPS. The image has Apache, PHP 8.3 with OPcache, and LibreOffice for the Work Experience Sheet PDF.

```sh
cp .env.example .env          # fill in APP_URL, DB_PASS, DATA_KEY and, optionally, Google and DPO settings
docker compose -f compose.prod.yaml up -d --build
```

- `compose.prod.yaml` runs the app and MySQL 8.4, both restarting on failure, with health checks. The database creates its tables on the first request.
- The app listens on `127.0.0.1:${APP_PORT}` (default 8080). Put a TLS reverse proxy such as Caddy in front of it, for example `pds.example.com { reverse_proxy 127.0.0.1:8080 }`. The proxy must send `X-Forwarded-Proto`, which Caddy does by default.
- Everything is set by environment variables, so no `config.local.php` is needed. Environment variables always win over `config.local.php`.
- People's data lives in two volumes: `db-data` (MySQL) and `app-data` (sign-in sessions, form versions, usage counters). Back up both, and keep `DATA_KEY` somewhere safe.
- To update, pull the new code and run the same `up -d --build`; the database updates itself.

## Deploy on Railway

`railway.json` tells Railway to build the `Dockerfile` and check `/` before switching traffic. Apache listens on Railway's `PORT`.

1. Create a project from the GitHub repo, then add a **MySQL** database to the same project.
2. On the app service, add a **volume** mounted at `/var/www/app/data` (sign-in sessions, form versions, usage counters).
3. Set the app's variables (Railway fills in the `${{MySQL.…}}` references):

   ```
   APP_URL=https://<your-app>.up.railway.app
   DB_DSN=mysql:host=${{MySQL.MYSQLHOST}};port=${{MySQL.MYSQLPORT}};dbname=${{MySQL.MYSQLDATABASE}};charset=utf8mb4
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   DATA_KEY=<32 random bytes, base64>
   ```

   Optional: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ADMIN_EMAILS`, `DPO_NAME`, `DPO_EMAIL`, `CONTACT_EMAIL`.
4. Under **Networking**, generate a domain. For Google sign-in, add `<APP_URL>/auth.php` as a redirect URI.

The database creates its tables on the first request. `APACHE_WORKERS` (default 12) caps how many requests run at once, to stay within small plans' memory.

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
   4. Set `DATA_KEY` (see [Data Privacy Act](#data-privacy-act-ra-10173)) and keep a copy of it.

If you leave Google settings empty, the app runs without the Sign in button. Shared hosting usually has no LibreOffice, so the Work Experience Sheet downloads as Word only.

## Admin

The admin page is `/admin.php`. There are two ways to sign in:

- **Google (easiest):** put your Google address in `ADMIN_EMAILS` in `config.local.php` (comma-separated for several). Signing in with that account opens the dashboard, and the account menu shows an **Admin dashboard** link. Admin visits are not counted in the usage numbers.
- **Password:** accounts stored in `data/admins.json`, outside the web root and never committed. Useful when Google sign-in is not set up. Manage them from a terminal:

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

### Usage statistics

The admin page shows how much the app is used, as proof of use. **Download CSV** exports the same numbers.

| Count | Meaning |
|---|---|
| Visitors | People who opened the app, counted once a day and once a month |
| App opens | Every time the app was opened |
| New PDS | PDS started |
| PDS worked on | Different PDS saved at least once that day or month |
| Print / PDF, Excel exports | Times a PDS was printed, saved as PDF or downloaded as Excel |
| Excel imports | Filled-out Excel files read in |
| New accounts, Sign-ins | Google accounts created and sign-ins |

- No personal data or cookies are used for counting. A visitor is recognized by a hash of their network address and browser, or their account if signed in.
- The hash's random key is deleted after each day and month, so past visitors cannot be traced.
- Bots, scripts and anyone signed in to the admin page are not counted. A visitor's browser must run the app to be counted.
- Counting starts on the first request after the update, when the database adds the `stats` tables by itself.
- Days follow `TIMEZONE` in the config, which defaults to Asia/Manila.

#### Standard analytics (optional)

For numbers others can check independently, add an open-source analytics service next to the built-in counts. Both work without cookies.

- **Umami** ([github.com/umami-software/umami](https://github.com/umami-software/umami), MIT): create a free Umami Cloud account or self-host it, add a website, then set `UMAMI_SCRIPT` (for example `https://cloud.umami.is/script.js`) and `UMAMI_WEBSITE_ID` in the config. Umami can make a public share link for its dashboard.
- **GoatCounter** ([github.com/arp242/goatcounter](https://github.com/arp242/goatcounter), EUPL): set `GOATCOUNTER_URL`, for example `https://yourname.goatcounter.com/count`.
- Set `ANALYTICS_DASHBOARD_URL` to show a link to that dashboard on the admin page.
- Page views are counted by the service itself. PDF and Excel exports and Excel imports are also sent to it as events.
- Keep the built-in counts too: free hosted plans keep data for a limited time, and only the built-in counts include server-side events.

## Run locally

```powershell
.\tools\setup-local.ps1   # once: creates the local database and config.local.php
docker compose up -d --build   # starts http://localhost:8000 (Apache + PHP 8.3)
```

- **Use Docker (recommended).** Apache serves many requests at once, like real hosting. PHP's built-in server (`php -S`, used by `tools\serve.ps1`) handles one request at a time on Windows. The browser loads the form's page images, fonts and scripts in parallel, so with `php -S` the live preview can stay blank and exports can time out.
- In the container, the database address comes from `compose.yaml`; the rest (user, password, Google keys) still comes from `config.local.php`. Environment variables always win over `config.local.php`.

- `setup-local.ps1` asks for your MySQL root password, which it does not save. It creates the database and user and writes `config.local.php`.
- `serve.ps1` turns on the PHP extensions the app needs, so you don't need a `php.ini`.
- For Google sign-in on your PC, add `http://localhost:8000/auth.php` as a redirect URI and put the client ID and secret in `config.local.php`.

## Development checks

Development tools only; the site itself needs nothing from Composer or npm. Requires PHP 8.2+ (8.3 recommended), Composer and Node 22.

```powershell
composer install          # PHPUnit and PHPStan
npm ci                    # ESLint
composer test             # unit tests (no database needed)
composer analyse          # PHPStan static analysis, level 5
composer lint             # PHP syntax of every file
npm run lint              # ESLint for public/*.js
gitleaks git .            # scan the git history for leaked secrets
```

- **CI:** GitHub Actions (`.github/workflows/ci.yml`) runs all of these on every push and pull request. PHP syntax is checked on PHP 8.0 and 8.3.
- **Tests:** `tests/` covers:
  - Encryption: round trip, wrong key, tampering.
  - The cross-site request check.
  - The Content-Security-Policy header.
  - Consent.
  - The server's data cleaning (`pds_clean`).
  - The form analyzer, against the official template.
- **Claude Code:** `.claude/settings.json` runs `tools/hooks/check-edit.mjs` after every edit: PHP syntax for `.php` files, ESLint for `public/*.js`.

## Styling

The UI uses Tailwind CSS v4.3.3 and daisyUI v5.7.50 through the standalone CLI, so it needs no npm. The built `public/app.css` is committed, so servers need no build step. After changing classes or `tools/css/app.css`, rebuild it:

```powershell
.\tools\build-css.ps1          # add -Watch to rebuild on every change
```

- The script downloads the pinned CLI and plugin into `tools/bin/` (not committed) and verifies their SHA-256 hashes.
- The official-form preview has its own `public/preview.css` and does not use Tailwind.
- The Inter font is self-hosted at `public/fonts/` (v5.3.0 of `@fontsource-variable/inter`, SIL Open Font License).
- Answers on the form preview use Arial Narrow Bold when the device has it, otherwise the self-hosted Roboto Condensed Bold (`@fontsource/roboto-condensed` 5.3.0, SIL Open Font License).
- Text uses the self-hosted Geist and Geist Mono variable fonts (`@fontsource-variable/geist` and `geist-mono` 5.3.0), and page and section titles use Instrument Serif (`@fontsource/instrument-serif` 5.3.0); all SIL Open Font License, licenses in `public/fonts/`. Inter stays as the fallback.
- **Work Experience Sheet:** filled into the official CSC Word template (`lib/templates/wes.docx`, `lib/wes-docx.php`). It downloads as Word, or as PDF converted from that same Word file by LibreOffice. The Docker image includes LibreOffice and Liberation fonts (Arial-compatible). On a host without LibreOffice, the PDF button is turned off and Word still works; set `SOFFICE` to LibreOffice's path if it is installed somewhere else.
- **Merge PDFs** combines the PDS, Work Experience Sheet and supporting documents into one PDF in the browser with pdf-lib 1.17.1 (MIT), kept at `public/vendor/pdf-lib-1.17.1/` and loaded only when used; files are never uploaded.
- The admin page renders PDFs with pdf.js 6.5.438 (Apache 2.0), kept at `public/vendor/pdfjs-6.5.438/` and loaded only when a PDF is added, with `isEvalSupported: false`.
- Date fields use the [Cally](https://wicky.nillia.ms/cally/) calendar web component (v0.9.2, MIT), kept at `public/vendor/cally-0.9.2.js` and styled by daisyUI's `cally` class. To upgrade, download `dist/cally.js` of the new version from npm into `public/vendor/` and update the script tag in `public/index.php`.

## Project layout

| Path | Contents |
|---|---|
| `public/` | Web root: pages, API endpoints, `app.js`, built `app.css`, `preview.css` (official-form look) and the form page images (`pds/`) |
| `public/psgc/` | Address suggestions built from the PSA PSGC list by `tools/build-psgc.py` |
| `public/sw.js` | Service worker that keeps the app's files for offline loading; answers are never cached there |
| `tools/css/app.css` | Tailwind + daisyUI source for `public/app.css` |
| `lib/` | Storage, sign-in, xlsx reader/writer, PDS cell map, `schema.json` (form fields) and the official template |
| `sql/schema.sql` | Database tables |
| `tools/` | Local setup, CSS build, admin accounts, lint and edit hooks, and the layout builder for new form revisions |
| `tests/` | PHPUnit tests |
| `Dockerfile`, `compose*.yaml` | Container image, local development and production setups |
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
- "Delete my PDS from this PC", and **New** without an account, delete the anonymous PDS from the server too.
- The anonymous cookie lasts 180 days and is renewed on each save.
- New PDS and Excel requests are rate-limited per network address.
- Version history keeps the last 15 versions of a PDS, without images, and deletes them with it.
- Set `CONTACT_EMAIL` in the config to show a contact for data requests on the privacy notice.

### Data Privacy Act (RA 10173)

A PDS holds sensitive personal information, so PDSync adds these safeguards:

- **Encryption at rest.** Every stored PDS and version is sealed with AES-256-GCM (`lib/crypto.php`). The key is `DATA_KEY` in `config.local.php` (32 random bytes, base64: `php -r "echo base64_encode(random_bytes(32));"`), or, if that is empty, `data/data.key`, created on first use. **Back the key up.** Without it the stored PDS cannot be opened. Records saved before encryption are sealed once by the database upgrade.
- **Consent.** Nothing is saved on the server until the person agrees to the privacy notice. The server refuses to create a PDS without it, and `consents` records the notice version and time. Raise `CONSENT_VERSION` in `lib/config.php` when the notice changes, and everyone is asked again.
- **Rights.** Signed-in users can "Download all my data" (`account.php?export`) and "Delete my account" from the account menu.
- **Accountability.** Admin sign-ins, dashboard views, downloads and form version changes go to `admin_log` and show on the dashboard's Data protection panel, with a breach response checklist.
- **DPO.** Set `DPO_NAME` and `DPO_EMAIL` to show your Data Protection Officer on the privacy notice and the admin page.
- Once you hold many people's sensitive data, check whether you need to register with the National Privacy Commission.

### Updating an existing install

Upload the new files; the database updates itself on the first request. `lib/config.php` creates missing tables from `sql/schema.sql`, adds new columns, and records the version in `data/schema-version`. The database user needs CREATE and ALTER rights, which cPanel's ALL PRIVILEGES includes.

### Address data

`public/psgc/` comes from the PSA Philippine Standard Geographic Code, as published at https://psgc.gitlab.io/api/. To refresh it, download `regions.json`, `provinces.json`, `cities-municipalities.json` and `barangays.json` from there into a folder, then run:

```sh
python tools/build-psgc.py <that folder>
```

## New form revisions (for example, Revised 2027)

Admins add a revised form from **Admin > Form versions** (`/forms.php`); no code changes are needed for most revisions.

1. **Upload the official Excel file** and name the revision. PDSync finds every question of the current form in it by the printed labels around each cell (the label on the left, the column header above), so moved rows and columns are followed. It also finds the tables and how many rows they now have, the continuation sheets, the checkboxes, the signature and photo boxes and the country list.
2. **Add the official PDF**, or save the Excel file as PDF. The admin page turns its pages into the preview images in the browser.
3. **Line up the boxes.** "Line up automatically" fits each page to its image by finding the printed grid lines, and snaps checkboxes onto their printed squares. You can fine-tune a whole page or move a single box.
4. **Check the questions.** Items PDSync could not match with certainty are listed with the cell it found; write another cell (for example `C1!D12`) to correct one. Labels that are new on the form are listed too, and **Add as a question** adds one to a section. Questions the new form no longer asks can be left out.
5. **Try it** with sample answers (preview and Excel), then **Make active**. People then fill out the new version; you can switch back from the list at any time.

How it works:

- Versions live in `data/forms/<id>/`: `form.json` (name, cell map, review report, page calibration), `schema.json` (questions), `layout.json` (preview boxes), `template.xlsx` and the page images. `data/forms/active.json` names the active version; without it, the built-in Revised 2026 is used.
- The built-in Revised 2026 version keeps using `lib/templates/`, `lib/schema.json` and the map in `lib/pds-xlsx.php`.
- Answers are stored by question, not by cell, so answers to questions that did not change carry over. A PDS made on an older version moves to the active one when it is next saved; its previous copy stays in version history.
- The library code is in `lib/forms.php` (versions), `lib/form-analyze.php` (matching), `lib/xlsx-geometry.php` and `lib/form-layout.php` (box positions), `lib/form-admin.php` (admin actions) and `public/form-calib.js` (lining up).
- `tools/build-layout.ps1` still rebuilds the built-in 2026 preview with Excel, if ever needed.

## Official Excel and preview

- Export fills the official CSC template (`lib/templates/pds-2026.xlsx`, sheets C1-C4), including checkboxes, the country dropdown, the signature, the photo and the thumbmark.
- Rows that do not fit on C1-C4 go on the template's continuation sheets C5-C11. The schema's `more` value is the number of rows each continuation sheet has.
- **Import Excel** reads a filled official PDS back in, including its continuation sheets.
- The preview and printout overlay your answers on images of the official pages. `tools/build-layout.ps1` regenerates those images and positions; it needs Excel on Windows.
