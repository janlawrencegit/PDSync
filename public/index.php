<?php
require __DIR__ . '/../lib/auth.php';
security_headers();
$user = auth_user();
$google = cfg('GOOGLE_CLIENT_ID') !== '';
$v = fn($f) => filemtime(__DIR__ . "/$f");
?>
<!doctype html>
<html lang="en" data-theme="pdsync">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PDSync</title>
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<link rel="stylesheet" href="preview.css?v=<?= $v('preview.css') ?>">
</head>
<body class="min-h-screen bg-base-200 text-base-content antialiased">
<header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-base-300 bg-base-100/95 px-4 backdrop-blur">
  <a href="./" class="flex shrink-0 items-center gap-2.5">
    <span class="grid size-9 place-items-center rounded-lg bg-primary text-sm font-bold text-primary-content">PDS</span>
    <span class="hidden leading-tight sm:block">
      <span class="block font-semibold">PDSync</span>
      <span class="block text-[11px] text-base-content/60">CS Form No. 212 &middot; Revised 2026</span>
    </span>
  </a>
  <label class="input input-sm hidden w-72 md:flex" title="Used as the file name when exporting">
    <svg class="size-4 opacity-50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
    <input id="inp-name" type="text" spellcheck="false" aria-label="File name">
  </label>
  <div class="ml-auto flex items-center gap-1">
    <label class="btn btn-ghost btn-sm">Import Excel<input id="inp-xlsx" type="file" accept=".xlsx" hidden></label>
    <button id="btn-clear" class="btn btn-ghost btn-sm">New</button>
    <button id="btn-export-open" class="btn btn-primary btn-sm">Export</button>
  </div>
<?php if ($user): ?>
  <div class="dropdown dropdown-end">
    <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-2" title="<?= h($user['email']) ?>">
      <span class="grid size-7 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary"><?= h(strtoupper(substr($user['name'] ?: $user['email'], 0, 1))) ?></span>
      <span class="hidden max-w-36 truncate lg:inline"><?= h($user['name'] ?: $user['email']) ?></span>
    </div>
    <ul tabindex="0" class="menu dropdown-content z-40 mt-2 w-56 rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
      <li class="menu-title truncate"><?= h($user['email']) ?></li>
      <li><button id="btn-docs">My PDS</button></li>
      <li><form method="post" action="auth.php?logout" id="form-logout" class="contents"><button>Sign out</button></form></li>
    </ul>
  </div>
<?php elseif ($google): ?>
  <a class="btn btn-outline btn-sm gap-2" href="auth.php?login" title="Save several PDS to your account and copy details into new forms">
    <svg class="size-4" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>
    Sign in
  </a>
<?php endif; ?>
</header>

<div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] 2xl:grid-cols-[220px_minmax(0,1fr)_minmax(0,1fr)]">
  <nav class="lg:col-span-2 2xl:col-span-1">
    <div class="2xl:sticky 2xl:top-20 rounded-box border border-base-300 bg-base-100 p-2">
      <p class="hidden px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wider text-base-content/50 2xl:block">Sections</p>
      <div id="tabs" class="flex gap-1 overflow-x-auto [scrollbar-width:none] 2xl:flex-col"></div>
      <div class="hidden px-3 pb-1 pt-3 2xl:block">
        <progress id="progress" class="progress progress-primary h-1.5 w-full" value="0" max="10"></progress>
        <p id="progress-text" class="mt-1 text-[11px] text-base-content/60"></p>
      </div>
    </div>
  </nav>

  <section class="min-w-0">
    <div class="rounded-box border border-base-300 bg-base-100">
      <main id="form" class="@container p-5 md:p-7"></main>
      <footer class="flex items-center gap-3 border-t border-base-300 px-5 py-3 md:px-7">
        <button id="btn-prev" class="btn btn-ghost btn-sm">&larr; Back</button>
        <span id="status" class="flex-1 truncate text-center text-xs text-base-content/60"></span>
        <button id="btn-next" class="btn btn-primary btn-sm">Next &rarr;</button>
      </footer>
    </div>
  </section>

  <aside class="min-w-0 lg:sticky lg:top-20 lg:h-[calc(100vh-6rem)]">
    <div class="flex h-[80vh] flex-col overflow-hidden rounded-box border border-base-300 bg-base-100 lg:h-full">
      <div class="flex flex-wrap items-center gap-2 border-b border-base-300 px-3 py-2">
        <span class="mr-auto flex items-center gap-2 text-sm font-semibold"><span class="size-2 rounded-full bg-success"></span>Live preview</span>
        <div class="join seg" id="pv-pages">
          <button class="btn btn-xs join-item" data-page="1">Page 1</button><button class="btn btn-xs join-item" data-page="2">2</button><button class="btn btn-xs join-item" data-page="3">3</button><button class="btn btn-xs join-item" data-page="4">4</button>
        </div>
        <div class="join seg" id="pv-fit">
          <button class="btn btn-xs join-item" data-fit="width">Fit width</button><button class="btn btn-xs join-item" data-fit="page">Fit page</button>
        </div>
        <button id="pv-print" class="btn btn-primary btn-xs">Print / PDF</button>
      </div>
      <iframe id="preview" title="Live preview" class="w-full flex-1 bg-[#e9ebee]"></iframe>
    </div>
  </aside>
</div>

<dialog id="dlg-docs" class="modal">
  <div class="modal-box max-w-2xl">
    <h3 class="text-lg font-semibold">My PDS</h3>
    <p class="text-sm text-base-content/60">Saved to your Google account, available on any device.</p>
    <ul id="docs-list" class="docs mt-4 grid max-h-[60vh] gap-2 overflow-y-auto"></ul>
    <form method="dialog" class="modal-action">
      <button class="btn btn-ghost">Close</button>
      <button id="docs-new" class="btn btn-primary" value="new">New blank PDS</button>
    </form>
  </div>
</dialog>

<dialog id="dlg-copy" class="modal">
  <div class="modal-box">
    <h3 class="text-lg font-semibold">Copy to a new PDS</h3>
    <p class="text-sm text-base-content/60">Creates a new PDS on the current form (<?= h(FORM_ID) ?>) with the sections you choose.</p>
    <label class="f mt-4"><span>Name</span><input id="copy-name" type="text" class="input w-full"></label>
    <fieldset id="copy-secs" class="mt-4 grid gap-2 rounded-box border border-base-300 p-4 text-sm"></fieldset>
    <form method="dialog" class="modal-action">
      <button class="btn btn-ghost">Cancel</button>
      <button class="btn btn-primary" value="copy">Copy</button>
    </form>
  </div>
</dialog>

<dialog id="dlg-export" class="modal">
  <div class="modal-box">
    <h3 class="text-lg font-semibold">Export PDS</h3>
    <p class="text-sm text-base-content/60">File name: <b id="dlg-name" class="text-base-content"></b></p>
    <div class="mt-4 grid gap-3">
      <button id="exp-pdf" class="choice"><strong>PDF</strong><span>Opens print settings, then your browser's print window. Choose "Save as PDF".</span></button>
      <button id="exp-xlsx" class="choice"><strong>Excel (.xlsx)</strong><span>Official CS Form 212 (Revised 2026) workbook.</span></button>
    </div>
    <form method="dialog" class="modal-action"><button class="btn btn-ghost">Cancel</button></form>
  </div>
</dialog>

<dialog id="dlg-crop" class="modal">
  <div class="modal-box w-fit max-w-[96vw]">
    <h3 class="text-lg font-semibold">Crop image</h3>
    <p class="text-sm text-base-content/60">Drag the box to move it, or drag a corner to resize.</p>
    <div class="mt-4 flex justify-center rounded-box bg-neutral p-3"><canvas id="crop-canvas" class="block max-w-full cursor-crosshair touch-none"></canvas></div>
    <form method="dialog" class="modal-action">
      <button class="btn btn-ghost" value="cancel">Cancel</button>
      <button class="btn btn-primary" value="use">Use image</button>
    </form>
  </div>
</dialog>

<dialog id="dlg-print" class="modal">
  <div class="modal-box max-w-5xl">
    <h3 class="text-lg font-semibold">Print settings</h3>
    <div class="mt-4 grid gap-5 md:grid-cols-[280px_1fr]">
      <div class="grid content-start gap-4">
        <label class="f"><span>Paper size</span>
          <select id="pr-paper" class="select w-full">
            <option value="a4">A4 (210 x 297 mm) - official form size</option>
            <option value="letter">Letter (8.5 x 11 in)</option>
            <option value="long">Long / Folio (8.5 x 13 in)</option>
            <option value="legal">Legal (8.5 x 14 in)</option>
          </select>
        </label>
        <label class="f"><span>Margins</span>
          <select id="pr-margin" class="select w-full">
            <option value="0">None</option>
            <option value="6">Narrow (6 mm)</option>
            <option value="12.7">Normal (12.7 mm)</option>
          </select>
        </label>
        <div class="f"><span>Pages</span>
          <div id="pr-pages" class="flex gap-4 text-sm">
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="1" checked> 1</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="2" checked> 2</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="3" checked> 3</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="4" checked> 4</label>
          </div>
        </div>
        <p class="text-xs text-base-content/60">Next, your browser's print window opens with these settings. Choose a printer, or "Save as PDF" to save as <b id="pr-name"></b>.pdf.</p>
      </div>
      <div class="pr-paper-view" id="pr-view"></div>
    </div>
    <form method="dialog" class="modal-action">
      <button class="btn btn-ghost">Cancel</button>
      <button id="pr-go" class="btn btn-primary" value="print">Print / Save as PDF</button>
    </form>
  </div>
</dialog>

<script>window.SCHEMA = <?= json_encode(pds_schema()) ?>; window.USER = <?= json_encode($user ? true : false) ?>;</script>
<script src="app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
