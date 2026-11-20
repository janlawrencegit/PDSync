<?php
// Syntax check of every PHP file (php -l), run in CI on the oldest supported PHP (8.0) and the newest.
//   php tools/lint.php
$root = dirname(__DIR__);
$bad = 0;
$count = 0;
foreach (['lib', 'public', 'tools', 'tests'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR)) continue;
        // Tests use PHPUnit, which needs a newer PHP; only the app itself must parse on PHP 8.0.
        if ($dir === 'tests' && PHP_VERSION_ID < 80200) continue;
        $count++;
        // -n: a syntax check needs no php.ini (and a broken one would only add noise).
        exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $bad++;
            echo implode("\n", $out), "\n";
        }
        $out = [];
    }
}
echo "$count files checked, $bad with errors (PHP " . PHP_VERSION . ")\n";
exit($bad ? 1 : 0);
