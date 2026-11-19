<?php
// Shared brand pieces for every page: the PDSync mark (a form with a check, on the navy of the PH flag) and the
// footer of the content pages. public/icon.svg is the same mark for the browser tab and the installed app.
function brand_mark(string $cls = 'size-9'): string
{
    return '<svg class="brand-mark ' . $cls . '" viewBox="0 0 48 48" aria-hidden="true">'
        . '<defs><linearGradient id="pds-mark-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2f55d4"/><stop offset="1" stop-color="#13286e"/></linearGradient></defs>'
        . '<rect width="48" height="48" rx="12" fill="url(#pds-mark-g)"/>'
        . '<rect x=".5" y=".5" width="47" height="47" rx="11.5" fill="none" stroke="#fff" stroke-opacity=".18"/>'
        . '<path d="M15.5 10h11.2L34 17.3V36a2.5 2.5 0 0 1-2.5 2.5h-16A2.5 2.5 0 0 1 13 36V12.5a2.5 2.5 0 0 1 2.5-2.5z" fill="#fff"/>'
        . '<path d="M26.7 10v5.3a2 2 0 0 0 2 2H34z" fill="#bfcdf7"/>'
        . '<rect x="17" y="21" width="11" height="2.2" rx="1.1" fill="#1d3a9a" fill-opacity=".38"/>'
        . '<rect x="17" y="26" width="7.5" height="2.2" rx="1.1" fill="#1d3a9a" fill-opacity=".38"/>'
        . '<circle cx="33" cy="34" r="7.6" fill="#f6c443" stroke="#13286e" stroke-width="2.4"/>'
        . '<path d="m29.6 34.1 2.3 2.3 4.4-4.6" fill="none" stroke="#13286e" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

// The mark with the name beside it; $sub is the small line under the name (a section, or the form's revision).
function brand_lockup(string $href, string $sub = '', string $nameCls = ''): string
{
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="brand" aria-label="PDSync home">' . brand_mark()
        . '<span class="brand-text ' . $nameCls . '"><span class="brand-name">PDSync</span>'
        . ($sub !== '' ? '<span class="brand-sub">' . htmlspecialchars($sub, ENT_QUOTES) . '</span>' : '') . '</span></a>';
}

function site_footer(): void
{
    ?>
<footer class="site-foot">
  <div class="site-foot-in">
    <span class="flex items-center gap-2"><?= brand_mark('size-5') ?><span>PDSync &middot; Personal Data Sheet helper</span></span>
    <nav class="flex flex-wrap gap-x-5 gap-y-1">
      <a href="./">Editor</a>
      <a href="library.php">My PDS</a>
      <a href="privacy.php">Privacy notice</a>
    </nav>
    <span class="site-foot-note">An independent tool, not an official website of the Civil Service Commission.</span>
  </div>
</footer>
<?php
}
