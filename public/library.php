<?php
// My PDS: a library of the signed-in user's PDS (grid or list, search, filters, Trash). Without an account it shows this
// browser's PDS and what signing in adds. Data comes from account.php and api.php; the page is drawn by library.js.
require __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/brand.php';
security_headers();
$user = auth_user();
$google = cfg('GOOGLE_CLIENT_ID') !== '';
$v = fn($f) => filemtime(__DIR__ . "/$f");
// Name boxes of page 1, so each thumbnail shows the name where the real form has it.
$layout = form_layout();
$thumb = [
    'aspect' => $layout['aspect'],
    'page' => form_page_url(1),
    'font' => round(10 * $layout['pt'][1], 3),
    'items' => array_values(array_map(fn($i) => ['k' => substr($i['id'], 2)] + array_intersect_key($i, array_flip(['x', 'y', 'w', 'h'])),
        array_filter($layout['items'], fn($i) => in_array($i['id'], ['f:surname', 'f:first_name', 'f:middle_name'], true)))),
];
$sections = array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title']], pds_schema());
$icon = fn($d, $cls = 'size-4') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$I = [
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'all' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'rows' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'pen' => '<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>My PDS - PDSync</title>
<meta name="theme-color" content="#1e3a8a">
<script>try { const t = localStorage.getItem('pds-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}</script>
<link rel="preload" href="fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/instrument-serif-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<?= analytics_tags() ?>
</head>
<body class="flex min-h-screen flex-col bg-base-200 text-base-content antialiased">
<header class="app-bar sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-base-300 bg-base-100/95 px-3 backdrop-blur sm:px-4">
  <?= brand_lockup('./', 'My PDS', 'hidden sm:flex') ?>
  <div class="ml-auto flex items-center gap-1">
    <a href="./" class="nav-link inline-flex" title="Open the PDS editor"><?= $icon($I['pen']) ?><span class="hidden sm:inline">Open editor</span></a>
<?php if ($user): ?>
    <div class="dropdown dropdown-end">
      <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-2 px-1.5" title="<?= h($user['email']) ?>">
        <span class="grid size-7 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary"><?= h(strtoupper(substr($user['name'] ?: $user['email'], 0, 1))) ?></span>
        <?= $icon('<path d="m6 9 6 6 6-6"/>', 'size-3.5 opacity-60 hidden sm:block') ?>
      </div>
      <ul tabindex="0" class="menu dropdown-content z-40 mt-2 w-60 rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
        <li class="menu-head"><div class="acct-head"><span class="acct-ava"><?= h(strtoupper(substr($user['name'] ?: $user['email'], 0, 1))) ?></span><span class="min-w-0"><b class="block truncate"><?= h($user['name'] ?: 'Your account') ?></b><small class="block truncate"><?= h($user['email']) ?></small></span></div></li>
        <?php if (in_array(strtolower($user['email']), admin_emails(), true)): ?><li><a href="admin.php"><?= $icon('<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>') ?>Admin dashboard</a></li><?php endif; ?>
        <li><a href="privacy.php"><?= $icon($I['shield']) ?>Privacy notice</a></li>
        <li><button type="submit" form="form-logout"><?= $icon($I['logout']) ?>Sign out</button></li>
      </ul>
      <form method="post" action="auth.php?logout" id="form-logout" hidden></form>
    </div>
<?php endif; ?>
  </div>
</header>

<div class="lib-layout w-full flex-1">
  <aside class="lib-side" aria-label="Library">
    <a href="./<?= $user ? '#new' : '' ?>" class="btn btn-primary w-full"><?= $icon($I['plus']) ?>New PDS</a>
    <nav class="mt-4 grid gap-1" id="lib-nav">
      <button class="lib-nav on" data-view="all"><?= $icon($I['all']) ?><span>All PDS</span><span class="ml-auto text-xs text-base-content/50" id="count-all"></span></button>
<?php if ($user): ?>
      <button class="lib-nav" data-view="trash"><?= $icon($I['trash']) ?><span>Trash</span></button>
<?php endif; ?>
    </nav>
    <p class="lib-tip hidden lg:flex"><?= $icon('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>', 'size-4 shrink-0') ?><span>Right-click a PDS, or use its <b>&middot;&middot;&middot;</b> button, for more options.</span></p>
  </aside>

  <main class="min-w-0">
    <section class="lib-hero">
      <div class="lib-hero-top">
        <div class="min-w-0">
          <p class="lib-hello" id="lib-hello">Your library</p>
          <h1 id="lib-title">My PDS</h1>
          <p class="lib-lead" id="lib-lead">Every Personal Data Sheet you are working on, in one place.</p>
        </div>
        <dl class="lib-stats" id="lib-stats" hidden></dl>
      </div>
      <div class="lib-tools">
        <label class="lib-search">
          <?= $icon($I['search'], 'size-[1.1rem] opacity-50') ?>
          <input id="lib-q" type="search" placeholder="Search by name" autocomplete="off" aria-label="Search your PDS">
        </label>
        <div class="flex flex-wrap gap-2" id="lib-filters">
          <select id="lib-status" class="select select-sm lib-chip" aria-label="Status">
            <option value="all">Any status</option>
            <option value="part">In progress</option>
            <option value="done">Complete</option>
          </select>
          <select id="lib-sort" class="select select-sm lib-chip" aria-label="Sort">
            <option value="edited">Last edited</option>
            <option value="name">Name A-Z</option>
            <option value="created">Date created</option>
          </select>
        </div>
      </div>
    </section>

    <div class="mb-3 mt-6 flex flex-wrap items-center gap-2">
      <h2 class="text-base font-semibold" id="lib-heading">All PDS</h2>
      <span class="text-sm text-base-content/60" id="lib-sub"></span>
      <button id="lib-empty-trash" class="btn btn-ghost btn-sm text-error" hidden>Empty Trash</button>
      <div class="join ml-auto" role="group" aria-label="View">
        <button class="btn btn-sm join-item" data-mode="grid" title="Grid" aria-label="Grid view"><?= $icon($I['grid']) ?></button>
        <button class="btn btn-sm join-item" data-mode="list" title="List" aria-label="List view"><?= $icon($I['rows']) ?></button>
      </div>
    </div>

    <div id="lib" class="lib-grid" aria-live="polite"></div>
    <div id="lib-signin" hidden></div>
  </main>
</div>
<?php site_footer(); ?>

<div id="lib-menu" class="lib-menu" popover="auto" role="menu"></div>
<div id="toasts" class="toast toast-end toast-bottom z-50" aria-live="polite"></div>

<dialog id="dlg-ask" class="modal">
  <form method="dialog" class="modal-box max-w-md">
    <h3 id="ask-title" class="text-lg font-semibold"></h3>
    <div id="ask-body" class="mt-2 text-sm text-base-content/75"></div>
    <input id="ask-input" class="input mt-4 w-full" hidden>
    <div id="ask-actions" class="modal-action"></div>
  </form>
</dialog>

<dialog id="dlg-copy" class="modal">
  <div class="modal-box">
    <h3 class="text-lg font-semibold">Copy sections to a new PDS</h3>
    <p class="text-sm text-base-content/60">Creates a new PDS on the current form (<?= h(pds_form_id()) ?>) with the sections you choose, for example to start a new application from your old details.</p>
    <label class="f mt-4"><span class="text-xs font-medium text-base-content/70">Name</span><input id="copy-name" type="text" class="input w-full"></label>
    <fieldset id="copy-secs" class="mt-4 grid gap-2 rounded-box border border-base-300 p-4 text-sm"></fieldset>
    <form method="dialog" class="modal-action">
      <button class="btn btn-ghost">Cancel</button>
      <button class="btn btn-primary" value="copy">Copy</button>
    </form>
  </div>
</dialog>

<script>window.LIB = <?= json_encode(['user' => (bool)$user, 'first' => $user ? explode(' ', trim((string)($user['name'] ?? '')))[0] : '', 'google' => $google, 'thumb' => $thumb, 'sections' => $sections]) ?>;</script>
<script src="ui.js?v=<?= $v('ui.js') ?>"></script>
<script src="library.js?v=<?= $v('library.js') ?>"></script>
</body>
</html>
