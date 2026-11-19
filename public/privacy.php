<?php
// Privacy notice, written for the Data Privacy Act of 2012 (RA 10173). CONTACT_EMAIL in the config adds a contact line.
require __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/brand.php';
security_headers();
$contact = cfg('CONTACT_EMAIL');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$ico = fn($d, $cls = 'size-4') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
// One card per section: its anchor, title and icon.
$sec = fn($id, $title, $path) => '<section id="' . $id . '" class="doc-card"><h2 class="doc-h">' . '<span class="doc-ico">' . $ico($path) . '</span>' . $title . '</h2>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy notice - PDSync</title>
<script>try { const t = localStorage.getItem('pds-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}</script>
<link rel="icon" href="icon.svg" type="image/svg+xml">
<link rel="preload" href="fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/instrument-serif-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
</head>
<body class="flex min-h-screen flex-col bg-base-200 text-base-content antialiased">
<header class="app-bar sticky top-0 z-30 flex h-16 items-center gap-3 px-3 sm:px-4">
  <?= brand_lockup('./', 'Privacy notice') ?>
  <a href="./" class="btn btn-ghost btn-sm ml-auto"><?= $ico('<path d="m15 18-6-6 6-6"/>') ?>Back to the form</a>
</header>
<main class="mx-auto w-full max-w-5xl flex-1 px-4 pb-16 pt-8 sm:px-6 sm:pt-12">
  <section class="page-hero">
    <span class="hero-icon"><?= $ico('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>', 'size-6') ?></span>
    <p class="eyebrow">Data Privacy Act of 2012 &middot; RA 10173</p>
    <h1 class="display-title">Privacy <em>notice</em></h1>
    <p class="lead">PDSync helps you fill out the CSC Personal Data Sheet (CS Form 212). This notice explains what happens to your information, as required by the Data Privacy Act of 2012 (Republic Act No. 10173).</p>
    <p class="mt-3 text-xs text-base-content/50">Last updated <?= date('F j, Y', filemtime(__FILE__)) ?></p>
  </section>

  <div class="mt-8 grid gap-3 sm:grid-cols-3">
    <div class="promise"><span class="doc-ico"><?= $ico('<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>') ?></span><b>Never sold or shared</b><span>Your PDS is used only to provide this service to you.</span></div>
    <div class="promise"><span class="doc-ico"><?= $ico('<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>') ?></span><b>Delete it any time</b><span>It is removed from our server right away.</span></div>
    <div class="promise"><span class="doc-ico"><?= $ico('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') ?></span><b>Encrypted and private</b><span>HTTPS, Google sign-in, and no share links.</span></div>
  </div>

  <div class="mt-10 grid items-start gap-8 lg:grid-cols-[12.5rem_1fr]">
    <nav class="toc" aria-label="On this page">
      <p class="toc-title">On this page</p>
      <a href="#keep">What we keep</a>
      <a href="#where">Where it is kept</a>
      <a href="#long">How long we keep it</a>
      <a href="#rights">Your choices and rights</a>
      <a href="#security">Security</a>
      <?php if ($contact !== ''): ?><a href="#contact">Contact</a><?php endif; ?>
    </nav>
    <div class="grid gap-4">
      <?= $sec('keep', 'What we keep', '<path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/>') ?>
        <ul>
          <li><b>Your PDS answers</b>, including your photo, signature and thumbmark if you add them. We keep them only so you can continue, preview and export your PDS.</li>
          <li><b>If you sign in with Google:</b> your Google account ID, e-mail address and name. We never receive or keep your Google password.</li>
          <li><b>Usage counts</b>, such as how many people opened the app each day. They contain no personal information and no cookies are used for them.</li>
          <?php if ((cfg('UMAMI_SCRIPT') !== '' && cfg('UMAMI_WEBSITE_ID') !== '') || cfg('GOATCOUNTER_URL') !== ''): ?>
          <li><b>Visit statistics</b> are also counted with <?= cfg('GOATCOUNTER_URL') !== '' ? 'GoatCounter' : 'Umami' ?>, an open-source analytics service that uses no cookies and does not identify you. Your PDS answers are never sent to it.</li>
          <?php endif; ?>
        </ul>
      </section>

      <?= $sec('where', 'Where it is kept and who can see it', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>') ?>
        <ul>
          <li>Without signing in, your PDS is linked to this browser only, through a private cookie that page scripts cannot read. There are no share links.</li>
          <li>If you sign in, your PDS is linked to your Google account so you can open it on any device.</li>
          <li>We do not sell or share your PDS, and we do not use it for anything except providing this service to you.</li>
          <li>Your browser also keeps a copy so your work survives a lost connection. Deleting your PDS or signing out clears it.</li>
        </ul>
      </section>

      <?= $sec('long', 'How long we keep it', '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>') ?>
        <ul>
          <li>A PDS made without signing in is deleted after 180 days without changes.</li>
          <li>A PDS in your account stays until you delete it.</li>
          <li>Earlier versions (without photos and signatures) are kept for version history; only the last 15 are kept, and they are deleted with the PDS.</li>
        </ul>
      </section>

      <?= $sec('rights', 'Your choices and rights', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>') ?>
        <ul>
          <li><b>Get a copy:</b> use "Download a copy" in the &middot;&middot;&middot; menu.</li>
          <li><b>Correct it:</b> edit your PDS at any time.</li>
          <li><b>Delete it:</b> use "Delete my PDS from this PC" or, when signed in, "Delete this PDS" in the &middot;&middot;&middot; menu. It is removed from our server right away.</li>
          <li>On a shared computer, delete your PDS or sign out when you finish.</li>
          <li>You may also file a complaint with the National Privacy Commission (privacy.gov.ph).</li>
        </ul>
      </section>

      <?= $sec('security', 'Security', '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') ?>
        <ul>
          <li>Connections are encrypted (HTTPS). Sign-in uses Google's secure sign-in, and requests from other websites are blocked.</li>
        </ul>
      </section>

      <?php if ($contact !== ''): ?>
      <?= $sec('contact', 'Contact', '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>') ?>
        <ul><li>For questions or requests about your data, e-mail <a class="link" href="mailto:<?= $h($contact) ?>"><?= $h($contact) ?></a>.</li></ul>
      </section>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php site_footer(); ?>
</body>
</html>
