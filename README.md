# PDSync

Fill out the CSC Personal Data Sheet (CS Form 212, Revised 2026) with a live preview of the official form, then export it as PDF or as the official Excel file.

- **Without an account:** each PC/browser keeps its own PDS (HttpOnly cookie). There are no share links: a PDS is reachable only from that browser, or from the owner's Google account.
- **Signed in with Google (optional):**
  - **My PDS** (`/library.php`) is a library of all your PDS, as a grid or list, with search, a status filter and sorting.
  - Each PDS has a menu (also on right-click): open, open in a new tab, rename, make a copy, copy chosen sections to a new PDS, download Excel or a JSON copy, and move to the Trash.
  - The Trash keeps deleted PDS for 30 days, with Restore and Delete forever.
  - Your PDS follows you to any device.

What helps people fill it out:

- **Guidance:** hints from the official form under each field, an N/A button per field, and **Fill empty with N/A** per section.
- **Checks:** gentle notes for values that look wrong, such as height in centimeters, a TIN with too few digits, or a "To" date before "From". They never block saving.
- **Progress:** each section shows a check mark when every item is answered and a half circle when started. Export lists what is still empty, with links to each item.
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
.\tools\serve.ps1         # starts http://localhost:8000
```

- `setup-local.ps1` asks for your MySQL root password, which it does not save. It creates the database and user and writes `config.local.php`.
- `serve.ps1` turns on the PHP extensions the app needs, so you don't need a `php.ini`.
- For Google sign-in on your PC, add `http://localhost:8000/auth.php` as a redirect URI and put the client ID and secret in `config.local.php`.

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
- The admin page renders PDFs with pdf.js 3.11.174 (Apache 2.0), kept at `public/vendor/pdfjs-3.11.174/`.
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
- "Delete my PDS from this PC", and **New** without an account, delete the anonymous PDS from the server too.
- The anonymous cookie lasts 180 days and is renewed on each save.
- New PDS and Excel requests are rate-limited per network address.
- Version history keeps the last 15 versions of a PDS, without images, and deletes them with it.
- Set `CONTACT_EMAIL` in the config to show a contact for data requests on the privacy notice.

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
