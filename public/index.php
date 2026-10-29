<?php
require __DIR__ . '/../lib/auth.php';
security_headers();
$user = auth_user();
$google = cfg('GOOGLE_CLIENT_ID') !== '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PDSync</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<header class="bar">
  <strong>PDSync</strong>
  <label class="fname" title="Used as the file name when exporting">
    <span>File name</span>
    <input id="inp-name" type="text" spellcheck="false">
  </label>
  <div class="actions">
    <label class="btn">Import Excel<input id="inp-xlsx" type="file" accept=".xlsx" hidden></label>
    <button id="btn-save">Share</button>
    <button id="btn-clear">New</button>
    <button id="btn-export-open" class="primary">Export</button>
  </div>
  <div class="acct">
<?php if ($user): ?>
    <button id="btn-docs">My PDS</button>
    <span class="muted" title="<?= h($user['email']) ?>"><?= h($user['name'] ?: $user['email']) ?></span>
    <form method="post" action="auth.php?logout" id="form-logout"><button>Sign out</button></form>
<?php elseif ($google): ?>
    <a class="btn" href="auth.php?login" title="Save several PDS to your account and copy details into new forms">Sign in with Google</a>
<?php endif; ?>
  </div>
</header>
<dialog id="dlg-docs" class="docs-dlg">
  <h3>My PDS</h3>
  <p class="muted small">Saved to your Google account, available on any device.</p>
  <ul id="docs-list" class="docs"></ul>
  <form method="dialog" class="pr-actions">
    <button>Close</button>
    <button id="docs-new" class="primary" value="new">New blank PDS</button>
  </form>
</dialog>
<dialog id="dlg-copy">
  <h3>Copy to a new PDS</h3>
  <p class="muted small">Creates a new PDS on the current form (<?= h(FORM_ID) ?>) with the sections you choose.</p>
  <label class="f">Name<input id="copy-name" type="text"></label>
  <fieldset class="copy-secs" id="copy-secs"></fieldset>
  <form method="dialog" class="pr-actions">
    <button>Cancel</button>
    <button class="primary" value="copy">Copy</button>
  </form>
</dialog>
<dialog id="dlg-export">
  <h3>Export PDS</h3>
  <p class="muted">File name: <b id="dlg-name"></b></p>
  <div class="choices">
    <button id="exp-pdf" class="choice"><strong>PDF</strong><span>Opens the print dialog. Choose "Save as PDF" as the destination.</span></button>
    <button id="exp-xlsx" class="choice"><strong>Excel (.xlsx)</strong><span>Official CS Form 212 (Revised 2026) workbook.</span></button>
  </div>
  <form method="dialog"><button>Cancel</button></form>
</dialog>
<dialog id="dlg-crop" class="crop-dlg">
  <h3>Crop image</h3>
  <p class="muted small">Drag the box to move it, or drag a corner to resize.</p>
  <div class="crop-stage"><canvas id="crop-canvas"></canvas></div>
  <form method="dialog" class="pr-actions">
    <button value="cancel">Cancel</button>
    <button value="use" class="primary">Use image</button>
  </form>
</dialog>
<dialog id="dlg-print" class="print-dlg">
  <h3>Print settings</h3>
  <div class="print-body">
    <div class="print-opts">
      <label>Paper size
        <select id="pr-paper">
          <option value="a4">A4 (210 x 297 mm) - official form size</option>
          <option value="letter">Letter (8.5 x 11 in)</option>
          <option value="long">Long / Folio (8.5 x 13 in)</option>
          <option value="legal">Legal (8.5 x 14 in)</option>
        </select>
      </label>
      <label>Margins
        <select id="pr-margin">
          <option value="0">None</option>
          <option value="6">Narrow (6 mm)</option>
          <option value="12.7">Normal (12.7 mm)</option>
        </select>
      </label>
      <fieldset>
        <legend>Pages</legend>
        <div class="pr-pages" id="pr-pages">
          <label><input type="checkbox" value="1" checked> 1</label>
          <label><input type="checkbox" value="2" checked> 2</label>
          <label><input type="checkbox" value="3" checked> 3</label>
          <label><input type="checkbox" value="4" checked> 4</label>
        </div>
      </fieldset>
      <p class="muted small">Next, your browser's print window opens with these settings. Choose a printer, or "Save as PDF" to save as <b id="pr-name"></b>.pdf.</p>
    </div>
    <div class="pr-paper-view" id="pr-view"></div>
  </div>
  <form method="dialog" class="pr-actions">
    <button>Cancel</button>
    <button id="pr-go" class="primary" value="print">Print / Save as PDF</button>
  </form>
</dialog>
<div class="layout">
  <div class="editor">
    <div id="share" class="share" hidden></div>
    <nav id="tabs" class="tabs"></nav>
    <main id="form"></main>
    <footer class="nav">
      <button id="btn-prev">Back</button>
      <span id="status" class="muted"></span>
      <button id="btn-next" class="primary">Next</button>
    </footer>
  </div>
  <aside class="preview">
    <div class="pv-bar">
      <strong>Live Preview</strong>
      <div class="pv-group" id="pv-pages">
        <button data-page="1">Page 1</button><button data-page="2">2</button><button data-page="3">3</button><button data-page="4">4</button>
      </div>
      <div class="pv-group" id="pv-fit">
        <button data-fit="width">Fit width</button><button data-fit="page">Fit page</button>
      </div>
      <button id="pv-print" class="primary">Print / PDF</button>
    </div>
    <iframe id="preview" title="Live preview"></iframe>
  </aside>
</div>
<script>window.SCHEMA = <?= json_encode(pds_schema()) ?>; window.USER = <?= json_encode($user ? true : false) ?>;</script>
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
