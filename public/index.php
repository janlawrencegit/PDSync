<?php
require __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/brand.php';
security_headers();
$user = auth_user();
$form = form_current();
$formName = form_name($form);
$google = cfg('GOOGLE_CLIENT_ID') !== '';
$v = fn($f) => filemtime(__DIR__ . "/$f");
$icon = fn($d, $cls = 'size-4') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$I = [
    'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
    'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    'folder' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/>',
    'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'eyeoff' => '<path d="M9.9 4.2A10 10 0 0 1 12 4c6.4 0 10 8 10 8a17 17 0 0 1-2.2 3.2M6.6 6.6A17 17 0 0 0 2 12s3.6 8 10 8a9.7 9.7 0 0 0 5.4-1.6"/><path d="m2 2 20 20"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    'pencil' => '<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'library' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'minus' => '<path d="M5 12h14"/>',
    'expand' => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    'width' => '<path d="M21 12H3M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
    'page' => '<rect x="6" y="2" width="12" height="20" rx="1.5"/>',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>PDSync - CS Form No. 212 Personal Data Sheet</title>
<meta name="description" content="Fill out the CSC Personal Data Sheet (<?= h($formName) ?>) with a live preview, then export it as PDF or the official Excel file.">
<meta name="theme-color" content="#1e3a8a">
<script nonce="<?= csp_nonce() ?>">
  // Applies the saved theme before the page paints, so dark mode does not flash white.
  try { const t = localStorage.getItem('pds-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
</script>
<link rel="preload" href="fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/instrument-serif-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<link rel="stylesheet" href="preview.css?v=<?= $v('preview.css') ?>">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<?= analytics_tags() ?>
</head>
<body class="min-h-screen bg-base-200 text-base-content antialiased">
<a href="#form" class="skip-link">Skip to the form</a>
<header class="app-bar sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-base-300 bg-base-100/95 px-3 backdrop-blur sm:gap-3 sm:px-4">
  <a href="./" class="brand" aria-label="PDSync home"><?= brand_mark() ?></a>
  <div class="doc-head">
    <input id="inp-name" class="doc-title" type="text" spellcheck="false" autocomplete="off" aria-label="File name, used when you export" title="File name, used when you export. Click to rename.">
    <div class="doc-meta">
      <button id="save-pill" class="save-pill" data-state="idle" type="button" title="Save status">
        <span class="dot" aria-hidden="true"></span><span class="txt">Not saved yet</span>
      </button>
      <span class="doc-form hidden md:inline"><?= h($form['title']) ?> &middot; <?= h($form['revision']) ?></span>
    </div>
  </div>

  <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
<?php if ($user): ?>
    <a href="library.php" class="nav-link hidden lg:inline-flex" title="All your PDS"><?= $icon($I['library']) ?>My PDS</a>
    <span class="nav-sep hidden lg:block" aria-hidden="true"></span>
<?php endif; ?>
    <div class="tool-group hidden md:flex" role="toolbar" aria-label="File">
      <label class="tool-btn" title="Import a PDS Excel file (.xlsx)"><?= $icon($I['upload']) ?><span class="hidden xl:inline">Import</span><input id="inp-xlsx" type="file" accept=".xlsx" hidden></label>
      <button id="btn-clear" class="tool-btn" title="Start a new PDS"><?= $icon($I['plus']) ?><span class="hidden xl:inline">New</span></button>
      <button id="btn-help" class="tool-btn" title="Help and instructions" aria-label="Help and instructions"><?= $icon($I['help']) ?><span class="hidden xl:inline">Help</span></button>
    </div>
    <button id="btn-export-open" class="btn btn-primary btn-sm gap-1.5"><?= $icon($I['download']) ?><span>Export</span></button>
    <div class="dropdown dropdown-end">
      <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="More" aria-label="More options"><?= $icon($I['more']) ?></div>
      <ul tabindex="0" class="menu dropdown-content z-40 mt-2 w-64 rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
        <li class="md:hidden"><label><?= $icon($I['upload']) ?>Import Excel<input id="inp-xlsx-m" type="file" accept=".xlsx" hidden></label></li>
        <li class="md:hidden"><button data-act="new"><?= $icon($I['plus']) ?>New PDS</button></li>
        <li class="md:hidden"><button data-act="help"><?= $icon($I['help']) ?>Help and instructions</button></li>
        <li class="md:hidden"><button data-act="rename-file"><?= $icon($I['file']) ?>File name</button></li>
        <li><button data-act="wes"><?= $icon($I['file']) ?>Work Experience Sheet</button></li>
        <li><button data-act="merge"><?= $icon('<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M4 16V5a1 1 0 0 1 1-1h11"/>') ?>Merge PDFs</button></li>
        <li><button data-act="versions"><?= $icon($I['history']) ?>Version history</button></li>
        <li><button data-act="backup"><?= $icon($I['download']) ?>Download a copy (.json)</button></li>
        <li><label><?= $icon($I['folder']) ?>Open a copy (.json)<input id="inp-backup" type="file" accept=".json,application/json" hidden></label></li>
        <li class="menu-title pt-3">Appearance</li>
        <li><div class="theme-pick join w-full p-1" role="group" aria-label="Theme">
          <button class="btn btn-xs join-item flex-1" data-theme-pick="">System</button>
          <button class="btn btn-xs join-item flex-1" data-theme-pick="pdsync">Light</button>
          <button class="btn btn-xs join-item flex-1" data-theme-pick="pdsync-dark">Dark</button>
        </div></li>
        <li class="menu-title pt-3">Privacy</li>
        <?php if ($user): ?>
        <li><button data-act="delete" class="text-error"><?= $icon($I['trash']) ?>Delete this PDS</button></li>
        <?php else: ?>
        <li><button data-act="delete" class="text-error" title="For shared computers"><?= $icon($I['trash']) ?>Delete my PDS from this PC</button></li>
        <?php endif; ?>
        <li><a href="privacy.php"><?= $icon($I['shield']) ?>Privacy notice</a></li>
      </ul>
    </div>
<?php if ($user): ?>
    <div class="dropdown dropdown-end">
      <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-2 px-1.5" title="<?= h($user['email']) ?>">
        <span class="grid size-7 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary"><?= h(strtoupper(substr($user['name'] ?: $user['email'], 0, 1))) ?></span>
        <?= $icon('<path d="m6 9 6 6 6-6"/>', 'size-3.5 opacity-60 hidden sm:block') ?>
      </div>
      <ul tabindex="0" class="menu dropdown-content z-40 mt-2 w-60 rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
        <li class="menu-head"><div class="acct-head"><span class="acct-ava"><?= h(strtoupper(substr($user['name'] ?: $user['email'], 0, 1))) ?></span><span class="min-w-0"><b class="block truncate"><?= h($user['name'] ?: 'Your account') ?></b><small class="block truncate"><?= h($user['email']) ?></small></span></div></li>
        <?php if (in_array(strtolower($user['email']), admin_emails(), true)): ?><li><a href="admin.php"><?= $icon('<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>') ?>Admin dashboard</a></li><?php endif; ?>
        <li><a href="library.php"><?= $icon($I['library']) ?>My PDS</a></li>
        <li class="menu-title">Your data</li>
        <li><a href="account.php?export" download><?= $icon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>') ?>Download all my data</a></li>
        <li><button type="button" data-acct="delete" class="text-error"><?= $icon('<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>') ?>Delete my account</button></li>
        <li class="mt-1 border-t border-base-300 pt-1"><button type="submit" form="form-logout"><?= $icon($I['logout']) ?>Sign out</button></li>
      </ul>
      <form method="post" action="auth.php?logout" id="form-logout" hidden></form>
    </div>
<?php elseif ($google): ?>
    <a class="btn btn-outline btn-sm gap-2" href="auth.php?login" title="Save several PDS to your account and open them on any device">
      <svg class="size-4" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>
      <span class="hidden sm:inline">Sign in</span>
    </a>
<?php endif; ?>
  </div>
</header>

<div id="layout" class="grid gap-4 p-3 pb-24 sm:p-4 lg:pb-4">
  <nav id="nav" aria-label="Form sections">
    <div class="2xl:sticky 2xl:top-20 rounded-box border border-base-300 bg-base-100 p-2">
      <p class="hidden px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wider text-base-content/50 2xl:block">Sections</p>
      <div class="tabs-wrap">
        <button class="tabs-arrow l" type="button" data-dir="-1" aria-label="Earlier sections" tabindex="-1"><?= $icon('<path d="m15 18-6-6 6-6"/>') ?></button>
        <div id="tabs" class="flex overflow-x-auto [scrollbar-width:none] 2xl:flex-col 2xl:gap-0.5"></div>
        <button class="tabs-arrow r" type="button" data-dir="1" aria-label="Later sections" tabindex="-1"><?= $icon('<path d="m9 18 6-6-6-6"/>') ?></button>
      </div>
      <div class="flex items-center gap-3 px-3 pb-1 pt-2 2xl:block 2xl:pt-3">
        <progress id="progress" class="progress progress-success h-1.5 w-full" value="0" max="10"></progress>
        <p id="progress-text" class="shrink-0 text-[11px] text-base-content/60 2xl:mt-1"></p>
        <button id="btn-check" type="button" class="chk-btn"><?= $icon($I['check'], 'size-3.5') ?>Check my PDS</button>
      </div>
    </div>
  </nav>

  <section id="editor" class="min-w-0">
    <div class="rounded-box border border-base-300 bg-base-100">
      <main id="form" class="@container p-4 sm:p-5 md:p-7" tabindex="-1"></main>
      <footer class="flex items-center gap-3 border-t border-base-300 px-4 py-3 sm:px-5 md:px-7">
        <button id="btn-prev" class="btn btn-ghost btn-sm">&larr; Back</button>
        <span id="status" class="flex-1 truncate text-center text-xs text-base-content/60" aria-live="polite"></span>
        <button id="btn-next" class="btn btn-primary btn-sm">Next &rarr;</button>
      </footer>
    </div>
  </section>

  <aside id="aside" class="min-w-0 lg:sticky lg:top-20 lg:h-[calc(100vh-6rem)]" aria-label="Live preview">
    <div class="flex h-[calc(100dvh-10.5rem)] flex-col overflow-hidden rounded-box border border-base-300 bg-base-100 lg:h-full">
      <div class="flex flex-wrap items-center gap-2 border-b border-base-300 px-3 py-2">
        <span class="mr-auto flex items-center gap-2 text-sm font-semibold"><span id="pv-dot" class="size-2 rounded-full bg-success"></span>Live preview<span id="pv-busy" class="loading loading-spinner loading-xs text-base-content/50" hidden></span></span>
        <div class="join seg" id="pv-pages">
          <button class="btn btn-xs join-item" data-page="1">Page 1</button><button class="btn btn-xs join-item" data-page="2">2</button><button class="btn btn-xs join-item" data-page="3">3</button><button class="btn btn-xs join-item" data-page="4">4</button><button class="btn btn-xs join-item" data-page="5" hidden title="Continuation sheets">Cont.</button>
        </div>
        <div class="join" id="pv-zoom" role="group" aria-label="Zoom">
          <button class="btn btn-xs join-item px-1.5" data-zoom="out" title="Zoom out (-)" aria-label="Zoom out"><?= $icon($I['minus'], 'size-3.5') ?></button>
          <button class="btn btn-xs join-item w-12 tabular-nums" id="pv-zoom-label" title="Zoom level. Click to fit the width">100%</button>
          <button class="btn btn-xs join-item px-1.5" data-zoom="in" title="Zoom in (+)" aria-label="Zoom in"><?= $icon($I['plus'], 'size-3.5') ?></button>
        </div>
        <div class="join seg" id="pv-fit" role="group" aria-label="Fit">
          <button class="btn btn-xs join-item px-1.5" data-fit="width" title="Fit width" aria-label="Fit width"><?= $icon($I['width'], 'size-3.5') ?></button><button class="btn btn-xs join-item px-1.5" data-fit="page" title="Fit one page" aria-label="Fit one page"><?= $icon($I['page'], 'size-3.5') ?></button>
        </div>
        <button id="pv-full" class="btn btn-ghost btn-xs btn-square" title="Full screen (Esc to close)" aria-label="Full screen"><?= $icon($I['expand'], 'size-3.5') ?></button>
        <button id="pv-hide" class="btn btn-ghost btn-xs hidden lg:inline-flex" title="Hide the preview to give the form more room"><?= $icon($I['eyeoff'], 'size-3.5') ?>Hide</button>
      </div>
      <div class="relative flex-1">
        <iframe id="preview" title="Live preview of the official form" class="absolute inset-0 size-full bg-[#e9ebee]"></iframe>
        <div id="pv-skeleton" class="pv-skeleton" aria-hidden="true"><div></div><div></div><div></div></div>
        <div id="pv-tip" class="pv-tip" hidden>
          <span>Click a box on the form to edit it. Drag to move around, and use Ctrl + scroll or pinch to zoom.</span>
          <button type="button" class="btn btn-ghost btn-xs" aria-label="Dismiss tip">Got it</button>
        </div>
      </div>
    </div>
  </aside>
</div>

<button id="pv-show" class="pv-show" type="button" title="Show the live preview"><?= $icon($I['eye']) ?><span>Preview</span></button>

<div id="mbar" class="mbar lg:hidden" role="group" aria-label="View">
  <button data-view="edit" class="on"><?= $icon($I['pencil']) ?>Edit</button>
  <button data-view="preview"><?= $icon($I['eye']) ?>Preview</button>
</div>

<div id="toasts" class="toast toast-end toast-bottom z-50 max-lg:bottom-16" aria-live="polite"></div>

<dialog id="dlg-ask" class="modal">
  <form method="dialog" class="modal-box max-w-md">
    <h3 id="ask-title" class="text-lg font-semibold"></h3>
    <div id="ask-body" class="mt-2 text-sm text-base-content/75"></div>
    <input id="ask-input" class="input mt-4 w-full" hidden>
    <div id="ask-actions" class="modal-action"></div>
  </form>
</dialog>



<dialog id="dlg-check" class="modal">
  <div class="modal-box max-w-xl p-0">
    <div id="chk-head" class="chk-head"></div>
    <div id="chk-body" class="chk-body"></div>
    <div class="chk-foot">
      <form method="dialog"><button class="btn btn-ghost btn-sm">Close</button></form>
      <button id="chk-export" class="btn btn-primary btn-sm gap-1.5"><?= $icon($I['download']) ?>Export</button>
    </div>
  </div>
  <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>

<dialog id="dlg-merge" class="modal">
  <div class="modal-box wes-box p-0">
    <div class="wes-head">
      <div class="min-w-0">
        <p class="eyebrow !mt-0">Submission packet</p>
        <h3 class="title-serif text-2xl">Merge PDFs</h3>
        <p class="text-xs text-base-content/60">Put your PDS, Work Experience Sheet and supporting documents (certificates, transcript, eligibility) into one PDF, in the order you choose.</p>
      </div>
      <form method="dialog"><button class="btn btn-ghost btn-sm btn-square" aria-label="Close"><?= $icon('<path d="M18 6 6 18M6 6l12 12"/>') ?></button></form>
    </div>
    <div class="wes-tools">
      <label class="btn btn-sm btn-primary btn-soft gap-1.5"><?= $icon($I['plus']) ?>Add files<input id="merge-input" type="file" accept="application/pdf,.pdf,image/png,image/jpeg" multiple hidden></label>
      <span class="text-xs text-base-content/55">PDF, JPG or PNG. Pictures become A4 pages.</span>
      <span id="merge-count" class="ml-auto text-xs font-medium text-base-content/60"></span>
    </div>
    <div id="merge-drop" class="merge-drop">
      <ol id="merge-list" class="merge-list"></ol>
      <div id="merge-empty" class="merge-empty">
        <span class="merge-empty-ico"><?= $icon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>', 'size-6') ?></span>
        <b>Drop files here, or use Add files</b>
        <p>Tip: export your PDS with <b>Export &rarr; PDF</b> and your Work Experience Sheet first, then add them here with your certificates.</p>
      </div>
    </div>
    <div class="chk-foot items-center">
      <span class="mr-auto flex items-center gap-1.5 text-xs text-base-content/55"><?= $icon('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', 'size-3.5') ?>Merged on your device. Files are never uploaded.</span>
      <form method="dialog"><button class="btn btn-ghost btn-sm">Close</button></form>
      <button id="merge-go" type="button" class="btn btn-primary btn-sm gap-1.5" disabled><?= $icon($I['download']) ?>Merge and download</button>
    </div>
  </div>
</dialog>

<dialog id="dlg-wes" class="modal">
  <div class="modal-box wes-box p-0">
    <div class="wes-head">
      <div class="min-w-0">
        <p class="eyebrow !mt-0">Attachment to CS Form No. 212</p>
        <h3 class="title-serif text-2xl">Work Experience Sheet</h3>
        <p class="text-xs text-base-content/60">Include only the work relevant to the position you are applying for, most recent first. Duties and accomplishments go here, as the PDS asks.</p>
      </div>
      <form method="dialog"><button class="btn btn-ghost btn-sm btn-square" aria-label="Close"><?= $icon('<path d="M18 6 6 18M6 6l12 12"/>') ?></button></form>
    </div>
    <div class="wes-tools">
      <div class="dropdown">
        <div tabindex="0" role="button" class="btn btn-sm btn-primary btn-soft gap-1.5"><?= $icon($I['plus']) ?>Add from Work Experience</div>
        <ul tabindex="0" id="wes-from" class="menu dropdown-content z-50 mt-2 w-80 max-h-72 flex-nowrap overflow-y-auto rounded-box border border-base-300 bg-base-100 p-2 shadow-lg"></ul>
      </div>
      <button id="wes-add" type="button" class="btn btn-sm btn-ghost gap-1.5"><?= $icon($I['plus']) ?>Blank entry</button>
      <span id="wes-count" class="ml-auto text-xs text-base-content/55"></span>
    </div>
    <div id="wes-list" class="wes-list"></div>
    <div class="chk-foot">
      <form method="dialog"><button class="btn btn-ghost btn-sm">Done</button></form>
      <button id="wes-docx" type="button" class="btn btn-ghost btn-sm gap-1.5" title="The official CSC template, filled in, as an editable Word file"><?= $icon($I['file']) ?>Word (.docx)</button>
      <button id="wes-pdf" type="button" class="btn btn-primary btn-sm gap-1.5"><?= $icon($I['download']) ?>Download PDF</button>
    </div>
  </div>
</dialog>

<dialog id="dlg-export" class="modal">
  <div class="modal-box max-w-lg">
    <h3 class="text-lg font-semibold">Export PDS</h3>
    <p class="text-sm text-base-content/60">File name: <b id="dlg-name" class="text-base-content"></b></p>
    <div id="review" class="review mt-4"></div>
    <div class="oath mt-4">
      <p class="oath-title"><?= $icon('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>', 'size-4') ?>Declaration on the form</p>
      <blockquote>I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct, and complete statement pursuant to the provisions of pertinent laws, rules, and regulations of the Republic of the Philippines. I authorize the agency head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.</blockquote>
      <label class="consent-check"><input id="oath-ok" type="checkbox" class="checkbox checkbox-primary checkbox-sm"><span>I have read this declaration. My answers are true, correct and complete, and I will sign it myself.</span></label>
    </div>
    <div class="mt-4 grid gap-3" id="exp-choices">
      <button id="exp-pdf" class="choice"><strong>PDF or print</strong><span>Choose paper and pages, then your browser's print window opens. Pick "Save as PDF" to get a file.</span></button>
      <button id="exp-merge" class="choice"><strong>Merge PDFs</strong><span>Combine your PDS, Work Experience Sheet and certificates into one PDF to submit. Done on your device.</span></button>
      <button id="exp-wes" class="choice"><strong>Work Experience Sheet</strong><span>The official CSC attachment for your duties and accomplishments, as PDF or Word.</span></button>
      <button id="exp-xlsx" class="choice"><strong>Excel (.xlsx)</strong><span>The official <?= h($formName) ?> workbook, including continuation sheets.</span></button>
    </div>
    <form method="dialog" class="modal-action"><button class="btn btn-ghost">Cancel</button></form>
  </div>
</dialog>

<dialog id="dlg-crop" class="modal">
  <div class="modal-box w-fit max-w-[96vw]">
    <h3 class="text-lg font-semibold">Crop image</h3>
    <p id="crop-tip" class="text-sm text-base-content/60">Drag the box to move it, or drag a corner to resize.</p>
    <div class="mt-4 flex justify-center rounded-box bg-neutral p-3"><canvas id="crop-canvas" class="block max-w-full cursor-crosshair touch-none"></canvas></div>
    <form method="dialog" class="modal-action">
      <button class="btn btn-ghost" value="cancel">Cancel</button>
      <button class="btn btn-primary" value="use">Use image</button>
    </form>
  </div>
</dialog>

<dialog id="dlg-sign" class="modal">
  <div class="modal-box max-w-xl">
    <h3 class="text-lg font-semibold">Draw your signature</h3>
    <p class="text-sm text-base-content/60">Sign with your finger, a stylus or the mouse.</p>
    <canvas id="sign-canvas" class="sign-canvas mt-4" width="1000" height="400" aria-label="Signature pad"></canvas>
    <form method="dialog" class="modal-action">
      <button id="sign-clear" type="button" class="btn btn-ghost mr-auto">Clear</button>
      <button class="btn btn-ghost" value="cancel">Cancel</button>
      <button class="btn btn-primary" value="use">Use signature</button>
    </form>
  </div>
</dialog>

<dialog id="dlg-versions" class="modal">
  <div class="modal-box max-w-md">
    <h3 class="text-lg font-semibold">Version history</h3>
    <p class="text-sm text-base-content/60">Earlier saved copies of this PDS, kept for a while. Photos and signatures stay as they are now.</p>
    <ul id="versions-list" class="docs mt-4 grid max-h-[55vh] gap-2 overflow-y-auto"></ul>
    <form method="dialog" class="modal-action"><button class="btn btn-ghost">Close</button></form>
  </div>
</dialog>

<dialog id="dlg-help" class="modal">
  <div class="modal-box max-w-2xl">
    <h3 class="text-lg font-semibold">How to fill out your PDS</h3>
    <div class="help mt-3">
      <h4>Rules from the official form</h4>
      <ul>
        <li>Answer every item. Write <b>N/A</b> if an item does not apply to you. Each field has an N/A button, and each section has <b>Fill empty with N/A</b>.</li>
        <li>Write names, schools and offices in full. Do not abbreviate.</li>
        <li>Dates use dd/mm/yyyy. Type them or pick them from the calendar.</li>
        <li>Work experience includes private employment and starts with your most recent work. Describe your duties in the separate Work Experience Sheet.</li>
        <li>References must not be related to you by blood or marriage.</li>
        <li>The photo is passport size (3.5 cm x 4.5 cm) and taken within the last 6 months.</li>
        <li>When a table has more entries than the form has rows, the extra entries go on the official continuation sheets automatically.</li>
      </ul>
      <h4>How PDSync helps</h4>
      <ul>
        <li>Your answers save automatically as you type, on this device and, once you agree to the privacy notice, encrypted on our server. The status next to the file name shows when it is saved.</li>
        <li>A check mark on a section means every item in it is answered. A half circle means it is started.</li>
        <li>On the preview, the item you are typing is highlighted. Click any box to jump to its item. Drag to move around; zoom with the + and - buttons, Ctrl + scroll, or two fingers.</li>
        <li>Yellow notes under a field point out values that look unusual, such as height in centimeters. They never stop you from saving.</li>
        <li><b>Check my PDS</b> lists empty items and possible mistakes, such as overlapping work dates. Click an item to go straight to it.</li>
        <li><b>Export</b> asks you to confirm the declaration, then gives you a PDF or the official Excel file. You can also merge your supporting documents into the PDF.</li>
        <li>The <b>Work Experience Sheet</b> fills the official template from your work entries, as a PDF or Word file. <b>Merge PDFs</b> joins any PDF files into one, right in your browser.</li>
        <li>Version history lets you go back to an earlier copy. "Download a copy" keeps a backup file on your device.</li>
      </ul>
      <h4>Keyboard</h4>
      <ul>
        <li><kbd class="kbd kbd-sm">Alt</kbd> + <kbd class="kbd kbd-sm">&darr;</kbd> in a date field opens the calendar.</li>
        <li><kbd class="kbd kbd-sm">Alt</kbd> + <kbd class="kbd kbd-sm">Page Up</kbd> / <kbd class="kbd kbd-sm">Page Down</kbd> goes to the previous or next section.</li>
      </ul>
      <p class="text-xs text-base-content/60">Using a shared computer? When you finish, open the <b>&middot;&middot;&middot;</b> menu and delete your PDS from this PC, or sign out. Read the <a class="link" href="privacy.php">privacy notice</a>.</p>
    </div>
    <form method="dialog" class="modal-action"><button class="btn btn-primary">Got it</button></form>
  </div>
</dialog>

<dialog id="dlg-consent" class="modal">
  <div class="modal-box max-w-lg p-0">
    <div class="consent-head">
      <span class="hero-icon !size-11 !rounded-xl"><?= $icon('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>', 'size-5') ?></span>
      <div><p class="eyebrow !mt-0">Data Privacy Act of 2012</p><h3 class="title-serif text-2xl">Your privacy comes first</h3></div>
    </div>
    <div class="consent-body">
      <p>Before PDSync saves your PDS on our server, please read how we handle it.</p>
      <ul class="consent-list">
        <li><b>What we keep.</b> Your PDS answers, which include <em>sensitive personal information</em> such as government ID numbers, age, civil status and your answers to Questions 34&ndash;40, plus your photo and signature if you add them.</li>
        <li><b>Why.</b> Only so you can fill out, save, preview and export your PDS. We never sell or share it.</li>
        <li><b>How it is protected.</b> Encrypted on our server, and only you can open it.</li>
        <li><b>Your rights.</b> Download or delete your data any time, and you may complain to the National Privacy Commission.</li>
      </ul>
      <label class="consent-check"><input id="consent-ok" type="checkbox" class="checkbox checkbox-primary checkbox-sm"><span>I have read the <a href="privacy.php" target="_blank" class="link link-primary">Privacy notice</a> and I agree that PDSync keeps my answers for this purpose.</span></label>
    </div>
    <div class="consent-foot">
      <button id="consent-no" class="btn btn-ghost btn-sm" type="button">Not now, keep it on this device</button>
      <button id="consent-yes" class="btn btn-primary btn-sm" type="button" disabled>Agree and continue</button>
    </div>
  </div>
</dialog>

<dialog id="dlg-welcome" class="modal">
  <div class="modal-box max-w-lg">
    <p class="text-xs font-semibold uppercase tracking-wider text-primary">Welcome to PDSync</p>
    <h3 class="title-serif mt-1 text-xl">Your Personal Data Sheet, <em>made easier</em></h3>
    <ol class="welcome mt-4">
      <li><b>Fill in each section.</b> Everything saves automatically. Hints under each field follow the official instructions.</li>
      <li><b>Check the live preview.</b> It shows your answers on the official <?= h($formName) ?>. Click any box on it to jump to that item.</li>
      <li><b>Export.</b> Get a PDF to print or the official Excel file.</li>
    </ol>
    <div class="mt-4 rounded-box bg-base-200 p-3 text-sm text-base-content/75">
      Your PDS is kept only for you: on this browser and, if you agree, encrypted on our server. Sign in with Google to keep several PDS in one place. We never share it.
      On a shared computer, delete it from the <b>&middot;&middot;&middot;</b> menu when you finish. <a class="link" href="privacy.php">Privacy notice</a>
    </div>
    <form method="dialog" class="modal-action"><button class="btn btn-primary">Start</button></form>
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
          <div id="pr-pages" class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="1" checked> 1</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="2" checked> 2</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="3" checked> 3</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="4" checked> 4</label>
            <label class="flex items-center gap-1.5" id="pr-cont" hidden><input type="checkbox" class="checkbox checkbox-primary checkbox-sm" value="5" checked> Continuation sheets</label>
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

<script nonce="<?= csp_nonce() ?>">window.SCHEMA = <?= json_encode(pds_schema()) ?>; window.USER = <?= json_encode($user ? true : false) ?>; window.FORM = <?= json_encode(['id' => $form['id'], 'name' => $formName]) ?>; window.CONSENT = <?= json_encode(CONSENT_VERSION) ?>;</script>
<script type="module" src="vendor/cally-0.9.2.js"></script>
<script src="ui.js?v=<?= $v('ui.js') ?>"></script>
<script src="app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
