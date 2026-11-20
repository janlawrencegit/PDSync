<?php
// Encryption at rest for PDS answers (Data Privacy Act: a PDS holds sensitive personal information). Each stored
// record is sealed with AES-256-GCM; a leaked database copy cannot be read without the key.
//
// The key is DATA_KEY in config.local.php (32 bytes, base64), or, when that is not set, data/data.key, created on
// first use. Back the key up: without it the stored PDS cannot be opened. Records saved before encryption stay
// readable and are sealed the next time they are saved (and once by the schema upgrade in lib/config.php).
require_once __DIR__ . '/config.php';

const SEAL_PREFIX = 'enc1:';

function data_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $cfg = cfg('DATA_KEY');
    if ($cfg !== '') {
        $key = base64_decode($cfg, true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('DATA_KEY must be 32 bytes, base64-encoded.');
        return $key;
    }
    $file = DATA_DIR . '/data.key';
    if (!is_file($file)) {
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
        $new = base64_encode(random_bytes(32));
        // Created only if still missing, so two first requests at once cannot write different keys.
        $fh = @fopen($file, 'x');
        if ($fh) {
            fwrite($fh, $new);
            fclose($fh);
            @chmod($file, 0600);
        }
    }
    $key = base64_decode(trim((string)file_get_contents($file)), true);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('data/data.key is damaged.');
    return $key;
}

// Where the key comes from, for the admin page.
function data_key_source(): string
{
    return cfg('DATA_KEY') !== '' ? 'DATA_KEY setting' : 'data/data.key file';
}

// $key is for tests; the app always uses data_key().
function seal(string $plain, ?string $key = null): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key ?? data_key(), OPENSSL_RAW_DATA, $iv, $tag, 'pdsync');
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return SEAL_PREFIX . base64_encode($iv . $tag . $ct);
}

// Opens a sealed value; a value saved before encryption is returned as it is.
function unseal(string $stored, ?string $key = null): string
{
    if (!str_starts_with($stored, SEAL_PREFIX)) return $stored;
    $raw = base64_decode(substr($stored, strlen(SEAL_PREFIX)), true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Stored data is damaged.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key ?? data_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'pdsync');
    if ($plain === false) throw new RuntimeException('Stored data could not be decrypted (wrong DATA_KEY?).');
    return $plain;
}

function is_sealed(string $stored): bool
{
    return str_starts_with($stored, SEAL_PREFIX);
}
