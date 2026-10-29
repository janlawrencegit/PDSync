<?php
// Manages admin accounts in data/admins.json: php tools/admin-user.php add|passwd|remove|list [username]
require __DIR__ . '/../lib/admin.php';

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
[, $cmd, $user] = $argv + [null, '', ''];

// Reads a password without showing it (PowerShell on Windows, stty elsewhere).
function ask_secret(string $prompt): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $ps = 'powershell -NoProfile -Command "$p = Read-Host \'' . $prompt . '\' -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        return rtrim((string)shell_exec($ps), "\r\n");
    }
    echo "$prompt: ";
    shell_exec('stty -echo');
    $v = rtrim((string)fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    echo "\n";
    return $v;
}

function new_password(): string
{
    $p = ask_secret('New password (min 12 characters)');
    if (strlen($p) < 12) exit("Password must be at least 12 characters.\n");
    if ($p !== ask_secret('Repeat password')) exit("Passwords do not match.\n");
    return password_hash($p, PASSWORD_DEFAULT);
}

$all = admin_accounts();
if ($cmd === 'list') exit(implode("\n", array_keys($all)) . "\n");
if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $user)) exit("Usage: php tools/admin-user.php add|passwd|remove|list <username> (3-32 letters, digits, . _ -)\n");

switch ($cmd) {
    case 'add':
        if (isset($all[$user])) exit("$user already exists; use passwd.\n");
        $all[$user] = ['hash' => new_password(), 'created' => date('c')];
        break;
    case 'passwd':
        if (!isset($all[$user])) exit("No admin named $user.\n");
        $all[$user]['hash'] = new_password();
        break;
    case 'remove':
        unset($all[$user]);
        break;
    default:
        exit("Unknown command $cmd.\n");
}
admin_save_accounts($all);
echo "Saved $cmd for $user.\n";
