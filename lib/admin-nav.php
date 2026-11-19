<?php
require_once __DIR__ . '/brand.php';
// The header shared by the admin pages (admin.php, forms.php): the PDSync mark, the admin sections as tabs, a link back
// to the app, and the account menu with Sign out. $active is 'dashboard' or 'forms'; $who is the signed-in admin
// (a Google email or a password username) and $google says which kind, since each signs out differently.
function admin_nav(string $active, ?string $who, bool $google): void
{
    $ico = fn($d, $cls = 'size-4') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    $tabs = [
        'dashboard' => ['admin.php', 'Dashboard', '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>'],
        'forms' => ['forms.php', 'Form versions', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>'],
    ];
    ?>
<header class="app-bar adm-bar">
  <?= brand_lockup('admin.php', 'Admin', 'hidden sm:flex') ?>
  <?php if ($who): ?>
  <nav class="adm-tabs" aria-label="Admin sections">
    <?php foreach ($tabs as $key => [$href, $label, $path]): ?>
    <a href="<?= $href ?>" class="adm-tab<?= $key === $active ? ' on' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $ico($path) ?><span><?= $label ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="ml-auto flex items-center gap-1.5">
    <a href="./" class="btn btn-sm btn-ghost" title="Open the PDS editor"><?= $ico('<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>') ?><span class="hidden md:inline">Open the app</span></a>
    <div class="dropdown dropdown-end">
      <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-2 px-1.5" title="<?= h($who) ?>" aria-label="Account">
        <span class="grid size-7 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary"><?= h(strtoupper(substr($who, 0, 1))) ?></span>
        <?= $ico('<path d="m6 9 6 6 6-6"/>', 'size-3.5 opacity-60') ?>
      </div>
      <ul tabindex="0" class="menu dropdown-content z-40 mt-2 w-64 rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
        <li class="menu-title"><span class="block text-[11px] font-normal uppercase tracking-wide">Signed in as admin</span><span class="block truncate font-medium text-base-content"><?= h($who) ?></span></li>
        <?php if ($google): ?>
        <li><a href="library.php"><?= $ico('<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>') ?>My PDS</a></li>
        <?php endif; ?>
        <li><a href="privacy.php"><?= $ico('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>') ?>Privacy notice</a></li>
        <li class="mt-1 border-t border-base-300 pt-1"><button type="submit" form="adm-logout"><?= $ico('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>') ?>Sign out</button></li>
      </ul>
    </div>
    <?php if ($google): ?>
    <form method="post" action="auth.php?logout" id="adm-logout" hidden></form>
    <?php else: ?>
    <form method="post" action="admin.php" id="adm-logout" hidden><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>"><input type="hidden" name="logout" value="1"></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</header>
<?php
}
