<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/local.php';
requireLocalDevelopmentServer();

if (!is_file(dirname(__DIR__) . '/config.php')) {
    header('Location: /setup.php', true, 303);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('itdoku_dev_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/inventory.php';
require_once dirname(__DIR__) . '/src/users.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectTo(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

try {
    $db = database();
} catch (PDOException|RuntimeException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Datenbankverbindung fehlgeschlagen. Bitte lokale Konfiguration prüfen.');
}

$now = time();
if (isset($_SESSION['user_id'], $_SESSION['started'], $_SESSION['activity'])
    && ($now - $_SESSION['started'] >= 28800 || $now - $_SESSION['activity'] >= 1800)) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$user = null;
if (isset($_SESSION['user_id'])) {
    $statement = $db->prepare('SELECT id, username, password_hash, can_manage_handover, can_manage_users FROM app_users WHERE id = ? AND active = 1');
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch() ?: null;
    if ($user === null || !passwordVersionMatches($user['password_hash'], $_SESSION['password_version'] ?? null)) {
        $user = null;
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } else {
        unset($user['password_hash']);
        $_SESSION['activity'] = $now;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(400);
        exit('Das Formular ist abgelaufen. Bitte die Seite neu laden.');
    }
    $action = $_POST['action'] ?? '';
    if (!is_string($action)) {
        http_response_code(400);
        exit('Ungültige Aktion.');
    }
    try {
        if ($action === 'login' && $user === null) {
            $username = field($_POST, 'Benutzername', 100);
            $password = $_POST['password'] ?? null;
            if (!is_string($password)) {
                throw new InvalidArgumentException('Ungültige Anmeldedaten.');
            }
            $db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 15 MINUTE');
            $count = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ?');
            $count->execute([$username]);
            $query = $db->prepare('SELECT id, password_hash FROM app_users WHERE username = ? AND active = 1');
            $query->execute([$username]);
            $account = $query->fetch();
            $dummy = '$2y$10$IfUPGKIx2zLYHIOi454ZE.snvcuK0N7ZQeEB9.Zlk5AIulTZbLyYm';
            $valid = password_verify($password, $account['password_hash'] ?? $dummy);
            if ((int) $count->fetchColumn() >= 5 || !$account || !$valid) {
                $db->prepare('INSERT INTO login_attempts (username) VALUES (?)')->execute([$username]);
                throw new InvalidArgumentException('Anmeldung fehlgeschlagen oder vorübergehend gesperrt.');
            }
            $db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            session_regenerate_id(true);
            $_SESSION = ['user_id' => (int) $account['id'], 'started' => $now, 'activity' => $now,
                'password_version' => hash('sha256', $account['password_hash']),
                'csrf' => bin2hex(random_bytes(32))];
            redirectTo('/');
        }
        if ($user === null) {
            http_response_code(403);
            exit('Anmeldung erforderlich.');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirectTo('/');
        }
        if ($action === 'change_password') {
            $newHash = changeOwnPassword(
                $db,
                (int) $user['id'],
                $_POST['current_password'] ?? null,
                $_POST['new_password'] ?? null,
                $_POST['confirm_password'] ?? null
            );
            $_SESSION['password_version'] = hash('sha256', $newHash);
            if (!session_regenerate_id(true)) {
                $_SESSION = [];
                session_destroy();
                http_response_code(500);
                exit('Das Passwort wurde geändert. Bitte melde dich erneut an.');
            }
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirectTo('/?profil=1&passwort_geaendert=1');
        }
        if ($action === 'create_user') {
            if (!$user['can_manage_users']) {
                http_response_code(403);
                exit('Diese Aktion ist nicht erlaubt.');
            }
            $password = $_POST['new_password'] ?? null;
            $confirmation = $_POST['confirm_password'] ?? null;
            if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException('Die Passwörter stimmen nicht überein.');
            }
            foreach (['can_manage_users', 'can_manage_handover'] as $permission) {
                if (isset($_POST[$permission]) && $_POST[$permission] !== '1') {
                    throw new InvalidArgumentException('Ungültige Berechtigung.');
                }
            }
            createUser(
                $db,
                $_POST['new_username'] ?? null,
                $password,
                isset($_POST['can_manage_users']),
                isset($_POST['can_manage_handover'])
            );
            redirectTo('/?konten=1&erstellt=1');
        }
        $idValue = $_POST['id'] ?? '';
        $id = $idValue === '' ? null : filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Ungültige Geräte-ID.');
        }
        if ($action === 'save_device') {
            if ($id !== null) {
                $check = $db->prepare('SELECT id FROM devices WHERE id = ?');
                $check->execute([$id]);
                if ($check->fetchColumn() === false) {
                    throw new InvalidArgumentException('Das Gerät existiert nicht.');
                }
            }
            saveDevice($db, $_POST, $id);
            redirectTo('/?geraete=1');
        }
        if (in_array($action, ['handover', 'return'], true) && $id !== null && $user['can_manage_handover']) {
            if ($action === 'handover') {
                createHandover($db, $id, $_POST);
            } else {
                returnHandover($db, $id, $_POST);
            }
            redirectTo('/?id=' . $id);
        }
        http_response_code(403);
        exit('Diese Aktion ist nicht erlaubt.');
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
        http_response_code(400);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? ($action === 'create_user' ? 'Das Benutzerkonto konnte nicht angelegt werden; der Benutzername ist möglicherweise bereits vergeben.'
                : 'Speichern nicht möglich. Bitte insbesondere die Inventarnummer und den Ausgabestatus prüfen.')
            : 'Datenbankfehler. Bitte das Serverprotokoll prüfen.';
        http_response_code(400);
    }
}

$id = null;
if (array_key_exists('id', $_GET)) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        exit('Ungültige Geräte-ID.');
    }
}
$device = null;
$history = [];
if ($user !== null && $id) {
    $query = $db->prepare('SELECT * FROM devices WHERE id = ?');
    $query->execute([$id]);
    $device = $query->fetch();
    if (!$device) {
        http_response_code(404);
        exit('Gerät nicht gefunden.');
    }
    if ($user['can_manage_handover']) {
        $query = $db->prepare('SELECT * FROM handovers WHERE device_id = ? ORDER BY id DESC');
        $query->execute([$id]);
        $history = $query->fetchAll();
    }
}
$devices = [];
$accounts = [];
$showInventory = isset($_GET['geraete']) && $id === null
    && !isset($_GET['neu']) && !isset($_GET['profil']) && !isset($_GET['konten']);
$showDashboard = $id === null && !isset($_GET['geraete']) && !isset($_GET['neu'])
    && !isset($_GET['profil']) && !isset($_GET['konten']);
if ($user !== null) {
    if ($showInventory) {
        $devices = $db->query(
            'SELECT d.*, EXISTS(SELECT 1 FROM handovers h WHERE h.device_id = d.id AND h.returned_on IS NULL) AS assigned
             FROM devices d ORDER BY d.inventory_number'
        )->fetchAll();
    }
    if ($user['can_manage_users'] && isset($_GET['konten'])) {
        $accounts = $db->query(
            'SELECT username, can_manage_users, can_manage_handover, active FROM app_users ORDER BY username'
        )->fetchAll();
    }
}
$active = $history && $history[0]['returned_on'] === null ? $history[0] : null;
$csrf = h($_SESSION['csrf']);
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>IT-Verwaltung – Entwicklung</title><link rel="stylesheet" href="/style.css"></head><body>
<main><header class="page-header"><div><h1>IT-Verwaltung</h1><p>Lokale Entwicklungsfassung – nur Testdaten</p></div>
<?php if ($user !== null): ?>
<nav class="header-actions" aria-label="Konto und Startseite">
<a class="button icon-button" href="/" aria-label="Zur Startseite" title="Zur Startseite">
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11 12 3l9 8v9h-6v-6H9v6H3z"></path></svg></a>
<details class="account-menu"><summary class="button icon-button" aria-label="Profilmenü" title="Profilmenü">
<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0z"></path></svg></summary>
<div class="account-dropdown"><p><?= h($user['username']) ?></p><a class="button" href="/?profil=1">Profileinstellungen</a>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button type="submit">Abmelden</button></form></div>
</details></nav><?php endif ?></header>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($user === null): ?>
<h2>Anmelden</h2><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="login">
<label>Benutzername <input name="Benutzername" required autocomplete="username"></label>
<label>Passwort <input type="password" name="password" required autocomplete="current-password"></label>
<button>Anmelden</button></form>
<?php else: ?>
<nav class="page-actions" aria-label="Hauptnavigation"><a class="button" href="/">Dashboard</a>
<a class="button" href="/?geraete=1">Geräteübersicht</a>
<a class="button" href="/?neu=1">Gerät anlegen</a>
<?php if ($user['can_manage_users']): ?><a class="button" href="/?konten=1">Benutzerkonten</a><?php endif ?></nav>
<?php if ($showDashboard): ?>
<h2>Dashboard</h2>
<p>Wähle einen Bereich aus.</p>
<div class="dashboard-grid">
<section class="dashboard-card"><h3>Geräteverwaltung</h3>
<p>Geräte und Standorte verwalten<?= $user['can_manage_handover'] ? ' sowie Ausgaben und Rücknahmen erfassen' : '' ?>.</p>
<a class="button" href="/?geraete=1">Geräteübersicht öffnen</a>
<a class="button" href="/?neu=1">Gerät anlegen</a></section>
<?php if ($user['can_manage_users']): ?>
<section class="dashboard-card"><h3>Benutzerkonten</h3>
<p>Konten anlegen und Berechtigungen vergeben.</p>
<a class="button" href="/?konten=1">Benutzerkonten öffnen</a></section>
<?php endif ?>
</div>
<p class="dashboard-future">Für später vorgesehen: persönliche Dashboard-Elemente und eine Übersicht der Ticketnachrichten. Diese Funktionen sind noch nicht verfügbar.</p>
<?php endif ?>
<?php if (isset($_GET['profil'])): ?>
<h2>Profileinstellungen</h2>
<?php if (isset($_GET['passwort_geaendert'])): ?><p role="status">Das Passwort wurde geändert.</p><?php endif ?>
<form method="post" action="/?profil=1">
<input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="change_password">
<label>Bisheriges Passwort <input type="password" name="current_password" required autocomplete="current-password"></label>
<label>Neues Passwort <input type="password" name="new_password" required autocomplete="new-password"></label>
<label>Neues Passwort wiederholen <input type="password" name="confirm_password" required autocomplete="new-password"></label>
<p>Das neue Passwort muss 12 bis 72 Bytes lang sein.</p><button>Passwort ändern</button></form>
<?php endif ?>
<?php if ($user['can_manage_users'] && isset($_GET['konten'])): ?>
<h2>Benutzerkonten</h2>
<?php if (isset($_GET['erstellt'])): ?><p role="status">Benutzerkonto angelegt.</p><?php endif ?>
<form method="post" action="/?konten=1">
<input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_user">
<label>Benutzername <input name="new_username" maxlength="100" required autocomplete="off"></label>
<label>Passwort <input type="password" name="new_password" required autocomplete="new-password"></label>
<label>Passwort wiederholen <input type="password" name="confirm_password" required autocomplete="new-password"></label>
<label><input type="checkbox" name="can_manage_users" value="1"> Benutzerkonten anlegen (Administration)</label>
<label><input type="checkbox" name="can_manage_handover" value="1"> Ausgaben verwalten und Schülerinnenkennungen sehen</label>
<p>Alle Konten dürfen Geräte verwalten. Passwörter müssen 12 bis 72 Bytes lang sein.</p>
<button>Benutzerkonto anlegen</button></form>
<table><thead><tr><th>Benutzername</th><th>Administration</th><th>Ausgaberecht</th><th>Status</th></tr></thead><tbody>
<?php foreach ($accounts as $account): ?><tr><td><?= h($account['username']) ?></td>
<td><?= $account['can_manage_users'] ? 'Ja' : 'Nein' ?></td><td><?= $account['can_manage_handover'] ? 'Ja' : 'Nein' ?></td>
<td><?= $account['active'] ? 'Aktiv' : 'Inaktiv' ?></td></tr><?php endforeach ?>
</tbody></table>
<?php endif ?>
<?php if ($device || isset($_GET['neu'])): ?>
<h2><?= $device ? 'Gerät bearbeiten' : 'Gerät anlegen' ?></h2>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_device">
<input type="hidden" name="id" value="<?= $device ? (int) $device['id'] : '' ?>">
<?php foreach (['Inventarnummer' => 'inventory_number', 'Geräteart' => 'category', 'Modell' => 'model',
    'Seriennummer' => 'serial_number', 'Standort' => 'location', 'Zustand' => 'device_condition', 'Notizen' => 'notes'] as $label => $key): ?>
<label><?= h($label) ?> <input name="<?= h($label) ?>" value="<?= h((string) ($device[$key] ?? '')) ?>"
    <?= in_array($key, ['serial_number', 'notes'], true) ? '' : 'required' ?>></label>
<?php endforeach ?><button>Speichern</button></form>
<?php endif ?>
<?php if ($device && $user['can_manage_handover']): ?>
<h2>Ausgabe und Rücknahme</h2>
<?php if ($active): ?>
<p>Aktuell ausgegeben an Kennung <?= h($active['recipient_id']) ?>, Klasse <?= h($active['recipient_class']) ?>
 (<?= $active['kind'] === 'loan' ? 'Ausleihe' : 'Dauerhafte Überlassung' ?>).</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="return">
<input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
<label>Rückgabedatum <input type="date" name="Rückgabedatum" required></label>
<label>Rückgabezustand <input name="Rückgabezustand" required maxlength="100"></label><button>Rücknahme speichern</button></form>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="handover">
<input type="hidden" name="id" value="<?= (int) $device['id'] ?>">
<label>Schülerinnenkennung <input name="Schülerinnenkennung" required maxlength="100"></label>
<label>Klasse <input name="Klasse" required maxlength="50"></label>
<label>Art <select name="Art"><option value="loan">Ausleihe</option><option value="permanent">Dauerhafte Überlassung</option></select></label>
<label>Ausgabedatum <input type="date" name="Ausgabedatum" required></label>
<label>Rückgabefrist (nur Ausleihe) <input type="date" name="Rückgabefrist"></label><button>Ausgabe speichern</button></form>
<?php endif ?>
<h3>Ausgabeverlauf</h3>
<?php if (!$history): ?><p>Keine Ausgaben erfasst.</p><?php else: ?>
<table><thead><tr><th>Ausgabe</th><th>Kennung</th><th>Klasse</th><th>Art</th><th>Frist</th><th>Rückgabe</th><th>Zustand</th></tr></thead><tbody>
<?php foreach ($history as $item): ?><tr><td><?= h($item['handed_over_on']) ?></td><td><?= h($item['recipient_id']) ?></td>
<td><?= h($item['recipient_class']) ?></td><td><?= $item['kind'] === 'loan' ? 'Ausleihe' : 'Dauerhaft' ?></td>
<td><?= h($item['due_on'] ?? '–') ?></td><td><?= h($item['returned_on'] ?? '–') ?></td><td><?= h($item['return_condition'] ?? '–') ?></td></tr><?php endforeach ?>
</tbody></table><?php endif ?><?php endif ?>
<?php if ($showInventory): ?>
<h2>Geräteübersicht</h2><table><thead><tr><th>Inventarnummer</th><th>Geräteart</th><th>Modell</th><th>Standort</th><th>Zustand</th><th>Status</th></tr></thead><tbody>
<?php foreach ($devices as $row): ?><tr><td><a class="button" href="/?id=<?= (int) $row['id'] ?>"><?= h($row['inventory_number']) ?></a></td>
<td><?= h($row['category']) ?></td><td><?= h($row['model']) ?></td><td><?= h($row['location']) ?></td>
<td><?= h($row['device_condition']) ?></td><td><?= $row['assigned'] ? 'Ausgegeben' : 'Am Standort / verfügbar' ?></td></tr><?php endforeach ?>
</tbody></table><?php if (!$devices): ?><p>Noch keine Geräte erfasst.</p><?php endif ?>
<?php endif ?>
<?php endif ?></main></body></html>
