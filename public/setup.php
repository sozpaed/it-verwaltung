<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/local.php';
requireLocalDevelopmentServer();

$configPath = $root . '/config.php';
$tokenPath = $root . '/install.token';
if (file_exists($configPath) || !is_readable($tokenPath)) {
    http_response_code(404);
    exit('Der Installationsassistent ist nicht verfügbar.');
}

require_once $root . '/src/database.php';
require_once $root . '/src/schema.php';
require_once $root . '/src/users.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('itdoku_setup_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function setupEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errors = [];
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? null;
    $token = $_POST['token'] ?? null;
    $dbPassword = $_POST['db_password'] ?? null;
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['confirmation'] ?? null;
    $tokenFile = null;
    $schemaStarted = false;
    try {
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(400);
            throw new InvalidArgumentException('Das Formular ist abgelaufen. Bitte die Seite neu laden.');
        }
        $tokenFile = @fopen($tokenPath, 'r+');
        if ($tokenFile === false || !flock($tokenFile, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Das Installations-Token ist nicht verfügbar oder eine Installation läuft bereits.');
        }
        $expectedToken = trim((string) stream_get_contents($tokenFile));
        if (!preg_match('/\A[0-9a-f]{64}\z/', $expectedToken)
            || !is_string($token) || !hash_equals($expectedToken, $token)) {
            http_response_code(403);
            throw new InvalidArgumentException('Das Installations-Token ist ungültig.');
        }
        if (file_exists($configPath)) {
            throw new RuntimeException('Eine Konfiguration existiert bereits. Es wurde nichts überschrieben.');
        }
        if (!is_string($dbPassword) || $dbPassword === '') {
            throw new InvalidArgumentException('Das Datenbankpasswort fehlt.');
        }
        if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('Die Administratorpasswörter stimmen nicht überein.');
        }
        validateUserCredentials($username, $password);
        $configuration = [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 3307,
            'DB_NAME' => 'itdoku_dev',
            'DB_USER' => 'itdoku_app',
            'DB_PASSWORD' => $dbPassword,
        ];
        $database = databaseWithConfiguration($configuration);
        $version = (string) $database->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/\A8\.4\.\d+(?:[-+][A-Za-z0-9._+-]+)?\z/', $version)) {
            throw new RuntimeException('Für diese Entwicklungsfassung wird MySQL 8.4 benötigt.');
        }
        $schemaStarted = true;
        initializeEmptyDatabase($database, $root . '/database/schema.sql');
        createUser($database, $username, $password, true, false);

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
        $previousUmask = umask(0077);
        try {
            $configFile = @fopen($configPath, 'x');
        } finally {
            umask($previousUmask);
        }
        if ($configFile === false) {
            throw new RuntimeException('config.php konnte nicht neu angelegt werden; vorhandene Dateien wurden nicht überschrieben.');
        }
        $written = fwrite($configFile, $content);
        $flushed = fflush($configFile);
        $closed = fclose($configFile);
        if ($written !== strlen($content) || !$flushed || !$closed) {
            if (!unlink($configPath)) {
                throw new RuntimeException('config.php ist unvollständig und konnte nicht entfernt werden. Bitte manuell prüfen.');
            }
            throw new RuntimeException('config.php konnte nicht vollständig geschrieben werden.');
        }
        flock($tokenFile, LOCK_UN);
        fclose($tokenFile);
        $tokenFile = null;
        if (!unlink($tokenPath)) {
            throw new RuntimeException('Installation abgeschlossen, aber install.token konnte nicht entfernt werden. Bitte die Datei manuell löschen.');
        }
        $_SESSION = [];
        session_destroy();
        header('Location: /', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        if (http_response_code() !== 403) {
            http_response_code(400);
        }
        $errors[] = $exception->getMessage();
    } catch (PDOException $exception) {
        http_response_code(500);
        error_log('Installations-Datenbankfehler: ' . $exception->getMessage());
        $errors[] = 'Datenbankfehler. Bitte Verbindung, Rechte und Serverprotokoll prüfen.';
    } catch (RuntimeException $exception) {
        http_response_code(500);
        $errors[] = $exception->getMessage();
    } finally {
        if (is_resource($tokenFile)) {
            flock($tokenFile, LOCK_UN);
            fclose($tokenFile);
        }
    }
    if ($schemaStarted && !file_exists($configPath)) {
        $errors[] = 'MySQL führt Schemaänderungen unmittelbar aus. Vor einem erneuten Versuch die Datenbank prüfen; vorhandene Tabellen wurden nicht gelöscht.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Methode nicht erlaubt.');
}
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>IT-Verwaltung einrichten</title><link rel="stylesheet" href="/style.css"></head><body>
<main><header><h1>IT-Verwaltung einrichten</h1><p>Nur lokale Entwicklungsfassung – nur Testdaten.</p></header>
<p>Der Assistent richtet eine zuvor angelegte, leere Datenbank ein und erstellt das erste Administratorkonto.
Vorhandene Tabellen werden nicht gelöscht oder überschrieben.</p>
<?php if ($errors): ?><div class="error" role="alert"><ul><?php foreach ($errors as $error): ?>
<li><?= setupEscape($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
<?php if (!file_exists($configPath)): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= setupEscape($_SESSION['csrf']) ?>">
<label>Einmaliges Installations-Token <input type="password" name="token" autocomplete="off" required></label>
<h2>Datenbankverbindung</h2>
<p>Lokaler Server: <strong>127.0.0.1:3307</strong>, Datenbank: <strong>itdoku_dev</strong>,
Benutzer: <strong>itdoku_app</strong>. Verwende nicht das MySQL-Root-Passwort.</p>
<label>Datenbankpasswort <input type="password" name="db_password" autocomplete="off" required></label>
<h2>Erstes Administratorkonto</h2>
<label>Benutzername <input name="username" value="<?= setupEscape($username) ?>" maxlength="100" required></label>
<label>Passwort (12 bis 72 Bytes) <input type="password" name="password" autocomplete="new-password" required></label>
<label>Passwort wiederholen <input type="password" name="confirmation" autocomplete="new-password" required></label>
<button>Prüfen und einrichten</button>
</form><?php else: ?><p>Die Einrichtung ist abgeschlossen. <a class="button" href="/">Zur Anmeldung</a></p><?php endif ?>
</main></body></html>
