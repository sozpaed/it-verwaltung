<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
if (is_file($root . '/config.php')) {
    fwrite(STDERR, "Eine Konfiguration existiert bereits. Kein Token erstellt.\n");
    exit(1);
}
$previousUmask = umask(0077);
try {
    $file = @fopen($root . '/install.token', 'x');
} finally {
    umask($previousUmask);
}
if ($file === false) {
    fwrite(STDERR, "Token konnte nicht erstellt werden. Prüfe, ob install.token bereits existiert.\n");
    exit(1);
}
$token = bin2hex(random_bytes(32));
$written = fwrite($file, $token);
$flushed = fflush($file);
$closed = fclose($file);
if ($written !== strlen($token) || !$flushed || !$closed) {
    fwrite(STDERR, "Token konnte nicht vollständig geschrieben werden. install.token bitte manuell prüfen.\n");
    exit(1);
}
echo "Einmaliges Installations-Token (nicht weitergeben):\n", $token, "\n";
