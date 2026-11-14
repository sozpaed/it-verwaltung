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
    $statement = $db->prepare('SELECT id, username, password_hash, can_manage_handover, can_manage_users, can_manage_inventory_settings FROM app_users WHERE id = ? AND active = 1');
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

$canManageInventorySettings = $user !== null && canManageInventorySettings($user);
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
            foreach (['can_manage_users', 'can_manage_handover', 'can_manage_inventory_settings'] as $permission) {
                if (isset($_POST[$permission]) && $_POST[$permission] !== '1') {
                    throw new InvalidArgumentException('Ungültige Berechtigung.');
                }
            }
            createUser(
                $db,
                $_POST['new_username'] ?? null,
                $password,
                isset($_POST['can_manage_users']),
                isset($_POST['can_manage_handover']),
                isset($_POST['can_manage_inventory_settings'])
            );
            redirectTo('/?konten=1&erstellt=1');
        }
        if ($action === 'set_inventory_settings_permission') {
            if (!$user['can_manage_users']) {
                http_response_code(403);
                exit('Diese Aktion ist nicht erlaubt.');
            }
            if (isset($_POST['can_manage_inventory_settings']) && $_POST['can_manage_inventory_settings'] !== '1') {
                throw new InvalidArgumentException('Ungültige Berechtigung.');
            }
            $accountId = settingId($_POST['account_id'] ?? null, true);
            setInventorySettingsPermission($db, (int) $user['id'], $accountId, isset($_POST['can_manage_inventory_settings']));
            redirectTo('/?konten=1');
        }
        if (in_array($action, ['save_inventory_setting', 'delete_inventory_setting'], true)) {
            if (!$canManageInventorySettings) {
                http_response_code(403);
                exit('Diese Aktion ist nicht erlaubt.');
            }
            $kind = field($_POST, 'kind', 20);
            $setting = settingId($_POST['setting_id'] ?? null, $action === 'delete_inventory_setting');
            if ($action === 'save_inventory_setting') {
                saveInventorySetting($db, $kind, $_POST, $setting);
            } else {
                if (($_POST['confirm_delete'] ?? null) !== '1') {
                    throw new InvalidArgumentException('Bitte das Löschen bestätigen.');
                }
                deleteInventorySetting($db, $kind, $setting);
            }
            redirectTo('/?stammdaten=' . rawurlencode($kind));
        }
        $idValue = $_POST['id'] ?? '';
        $id = $idValue === '' ? null : filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Ungültige Geräte-ID.');
        }
        if ($action === 'save_device') {
            $existingDevice = null;
            if ($id !== null) {
                $check = $db->prepare('SELECT * FROM devices WHERE id = ?');
                $check->execute([$id]);
                $existingDevice = $check->fetch();
                if ($existingDevice === false) {
                    throw new InvalidArgumentException('Das Gerät existiert nicht.');
                }
            }
            $deviceInput = configuredDeviceInput($db, $_POST, $existingDevice);
            if ($id !== null) {
                saveDevice($db, $deviceInput, $id);
            } else {
                $mode = $_POST['number_mode'] ?? 'manual';
                if ($mode === 'automatic') {
                    saveDeviceWithGeneratedNumber($db, $deviceInput);
                } elseif ($mode === 'manual') {
                    saveDevice($db, $deviceInput, null);
                } else {
                    throw new InvalidArgumentException('Ungültige Art der Inventarnummernvergabe.');
                }
            }
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
            ? (in_array($action, ['save_inventory_setting', 'delete_inventory_setting'], true)
                ? 'Die Inventareinstellung ist bereits vorhanden oder wird noch verwendet.'
                : ($action === 'create_user' ? 'Das Benutzerkonto konnte nicht angelegt werden; der Benutzername ist möglicherweise bereits vergeben.'
                    : 'Speichern nicht möglich. Bitte insbesondere die Inventarnummer und den Ausgabestatus prüfen.'))
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
$workplaces = [];
$settingKind = $_GET['stammdaten'] ?? null;
if ($settingKind !== null && (!is_string($settingKind) || !isset(settingKinds()[$settingKind]))) {
    http_response_code(400);
    exit('Ungültige Inventareinstellung.');
}
if ($user !== null && (isset($_GET['inventareinstellungen']) || $settingKind !== null) && !$canManageInventorySettings) {
    http_response_code(403);
    exit('Diese Seite ist nicht erlaubt.');
}
$settingLists = [];
$settingToEdit = null;
if ($user !== null && ($device || isset($_GET['neu']) || $settingKind !== null)) {
    foreach (array_keys(settingKinds()) as $kind) {
        $settingLists[$kind] = inventorySettings($db, $kind);
    }
}
if ($user !== null && $settingKind !== null && isset($_GET['edit'])) {
    try {
        $settingToEdit = inventorySetting($db, $settingKind, settingId($_GET['edit'], true));
    } catch (InvalidArgumentException $exception) {
        http_response_code(404);
        exit('Inventareinstellung nicht gefunden.');
    }
}
$showInventory = isset($_GET['geraete']) && $id === null
    && !isset($_GET['neu']) && !isset($_GET['profil']) && !isset($_GET['konten'])
    && !isset($_GET['inventareinstellungen']) && $settingKind === null;
$showDashboard = $id === null && !isset($_GET['geraete']) && !isset($_GET['neu'])
    && !isset($_GET['profil']) && !isset($_GET['konten']) && !isset($_GET['inventareinstellungen']) && $settingKind === null;
if ($user !== null) {
    if (isset($_GET['neu']) && $id === null) {
        $workplaces = $db->query(
            "SELECT inventory_number FROM devices
             WHERE inventory_number REGEXP 'PC(0[1-9]|[1-9][0-9])$' ORDER BY inventory_number"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($showInventory) {
        $devices = $db->query(
            "SELECT d.*, area.name AS area_name, device_group.name AS group_name, device_type.name AS type_name,
                    EXISTS(SELECT 1 FROM handovers h WHERE h.device_id = d.id AND h.returned_on IS NULL) AS assigned
             FROM devices d
             LEFT JOIN inventory_settings area ON area.id = d.inventory_area_id
             LEFT JOIN inventory_settings device_group ON device_group.id = d.inventory_group_id
             LEFT JOIN inventory_settings category ON category.id = d.inventory_category_id
             LEFT JOIN inventory_settings device_type ON device_type.id = category.type_id
             ORDER BY d.inventory_number"
        )->fetchAll();
    }
    if ($user['can_manage_users'] && isset($_GET['konten'])) {
        $accounts = $db->query(
            'SELECT id, username, can_manage_users, can_manage_handover, can_manage_inventory_settings, active FROM app_users ORDER BY username'
        )->fetchAll();
    }
}
$active = $history && $history[0]['returned_on'] === null ? $history[0] : null;
$csrf = h($_SESSION['csrf']);
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>IT-Verwaltung – Entwicklung</title><link rel="stylesheet" href="/style.css"><script src="/inventory.js" defer></script></head><body>
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
<?php if ($canManageInventorySettings): ?><p><a class="button" href="/?inventareinstellungen=1">Inventareinstellungen</a></p><?php endif ?>
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
<label><input type="checkbox" name="can_manage_inventory_settings" value="1"> Inventareinstellungen verwalten</label>
<p>Alle Konten dürfen Geräte verwalten. Passwörter müssen 12 bis 72 Bytes lang sein.</p>
<button>Benutzerkonto anlegen</button></form>
<table><thead><tr><th>Benutzername</th><th>Administration</th><th>Ausgaberecht</th><th>Inventareinstellungen</th><th>Status</th></tr></thead><tbody>
<?php foreach ($accounts as $account): ?><tr><td><?= h($account['username']) ?></td>
<td><?= $account['can_manage_users'] ? 'Ja' : 'Nein' ?></td><td><?= $account['can_manage_handover'] ? 'Ja' : 'Nein' ?></td><td>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="set_inventory_settings_permission">
<input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
<label><input type="checkbox" name="can_manage_inventory_settings" value="1" <?= $account['can_manage_inventory_settings'] ? 'checked' : '' ?>> Separates Recht</label>
<?php if ($account['can_manage_users']): ?><p>Administration hat unabhängig davon Zugriff.</p><?php endif ?>
<button>Recht speichern</button></form></td>
<td><?= $account['active'] ? 'Aktiv' : 'Inaktiv' ?></td></tr><?php endforeach ?>
</tbody></table>
<?php endif ?>
<?php if ($canManageInventorySettings && isset($_GET['inventareinstellungen'])): ?>
<h2>Inventareinstellungen</h2><p>Bereiche, Typen, Gruppen und Kategorien verwalten.</p>
<nav class="page-actions" aria-label="Inventareinstellungen">
<?php foreach (settingKinds() as $kind => $label): ?>
<a class="button" href="/?stammdaten=<?= h($kind) ?>"><?= h($label) ?></a>
<?php endforeach ?></nav>
<?php endif ?>
<?php if ($canManageInventorySettings && $settingKind !== null):
    $label = settingKinds()[$settingKind];
    $submittedSetting = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_inventory_setting' && $error !== '';
    $settingName = $submittedSetting && is_string($_POST['name'] ?? null) ? $_POST['name'] : ($settingToEdit['name'] ?? '');
    $settingCode = $submittedSetting && is_string($_POST['code'] ?? null) ? $_POST['code'] : ($settingToEdit['code'] ?? '');
    $settingType = $submittedSetting && is_string($_POST['type_id'] ?? null) ? $_POST['type_id'] : (string) ($settingToEdit['type_id'] ?? '');
?>
<h2><?= h($label) ?> verwalten</h2>
<a class="button" href="/?inventareinstellungen=1">Zurück zu Inventareinstellungen</a>
<form method="post">
<input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_inventory_setting">
<input type="hidden" name="kind" value="<?= h($settingKind) ?>">
<input type="hidden" name="setting_id" value="<?= $settingToEdit ? (int) $settingToEdit['id'] : '' ?>">
<label>Name <input name="name" maxlength="100" required value="<?= h($settingName) ?>"></label>
<?php if ($settingKind === 'area' || $settingKind === 'type'): ?>
<label>Kürzel <input name="code" maxlength="<?= $settingKind === 'area' ? 12 : 2 ?>" required value="<?= h($settingCode) ?>"></label>
<p><?= $settingKind === 'area' ? '2 bis 12 Buchstaben oder Ziffern, z. B. LNB oder E01.' : 'Genau zwei Buchstaben, z. B. PC oder MO.' ?></p>
<?php elseif ($settingKind === 'category'): ?>
<label>Typ <select name="type_id" required><option value="">Bitte auswählen</option>
<?php foreach ($settingLists['type'] as $type): ?><option value="<?= (int) $type['id'] ?>" <?= $settingType === (string) $type['id'] ? 'selected' : '' ?>><?= h($type['name'] . ' (' . $type['code'] . ')') ?></option><?php endforeach ?>
</select></label>
<?php endif ?>
<button><?= $settingToEdit ? 'Änderungen speichern' : h($label) . ' anlegen' ?></button>
<?php if ($settingToEdit): ?><a class="button" href="/?stammdaten=<?= h($settingKind) ?>">Abbrechen</a><?php endif ?>
</form>
<table><thead><tr><th>Name</th><?php if ($settingKind === 'area' || $settingKind === 'type'): ?><th>Kürzel</th><?php endif ?>
<?php if ($settingKind === 'category'): ?><th>Typ</th><?php endif ?><th>Aktionen</th></tr></thead><tbody>
<?php foreach ($settingLists[$settingKind] as $setting): ?><tr><td><?= h($setting['name']) ?></td>
<?php if ($settingKind === 'area' || $settingKind === 'type'): ?><td><?= h($setting['code']) ?></td><?php endif ?>
<?php if ($settingKind === 'category'):
    $typeName = '';
    foreach ($settingLists['type'] as $type) {
        if ((int) $type['id'] === (int) $setting['type_id']) {
            $typeName = $type['name'] . ' (' . $type['code'] . ')';
            break;
        }
    }
?><td><?= h($typeName) ?></td><?php endif ?>
<td><a class="button" href="/?stammdaten=<?= h($settingKind) ?>&amp;edit=<?= (int) $setting['id'] ?>">Bearbeiten</a>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_inventory_setting">
<input type="hidden" name="kind" value="<?= h($settingKind) ?>"><input type="hidden" name="setting_id" value="<?= (int) $setting['id'] ?>">
<label><input type="checkbox" name="confirm_delete" value="1" required> Löschen bestätigen</label><button>Löschen</button></form></td></tr>
<?php endforeach ?></tbody></table>
<p>Verwendete Einträge können nicht gelöscht werden. Änderungen an Kürzeln ändern bestehende Inventarnummern nicht.</p>
<?php endif ?>
<?php if ($device || isset($_GET['neu'])): ?>
<h2><?= $device ? 'Gerät bearbeiten' : 'Gerät anlegen' ?></h2>
<p>Die Inventarnummer entspricht der Bezeichnung aus der Inventarisierung. Weitere Geräteangaben sind optional.
Den Kaufpreis inklusive Mehrwertsteuer ohne Währungszeichen mit höchstens zwei Nachkommastellen eingeben.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_device">
<input type="hidden" name="id" value="<?= $device ? (int) $device['id'] : '' ?>">
<?php
$submittedDevice = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_device' && $error !== '';
$selectedArea = $submittedDevice && is_string($_POST['area_id'] ?? null) ? $_POST['area_id'] : (string) ($device['inventory_area_id'] ?? '');
$selectedGroup = $submittedDevice && is_string($_POST['group_id'] ?? null) ? $_POST['group_id'] : (string) ($device['inventory_group_id'] ?? '');
$legacyCategory = $device && $device['inventory_category_id'] === null && $device['category'] !== '';
$selectedCategory = $submittedDevice && is_string($_POST['category_id'] ?? null)
    ? $_POST['category_id'] : ($legacyCategory ? 'legacy' : (string) ($device['inventory_category_id'] ?? ''));
$selectedType = '';
foreach ($settingLists['category'] as $category) {
    if ($selectedCategory === (string) $category['id']) {
        $selectedType = (string) $category['type_id'];
        break;
    }
}
?>
<label>Bereich <select name="area_id"><option value="">Bitte auswählen</option>
<?php foreach ($settingLists['area'] as $area): ?><option value="<?= (int) $area['id'] ?>" <?= $selectedArea === (string) $area['id'] ? 'selected' : '' ?>><?= h($area['name'] . ' (' . $area['code'] . ')') ?></option><?php endforeach ?>
</select></label>
<label>Typ <select id="device-type" disabled><option value="">Wird durch die Kategorie bestimmt</option>
<?php foreach ($settingLists['type'] as $type): ?><option value="<?= (int) $type['id'] ?>" <?= $selectedType === (string) $type['id'] ? 'selected' : '' ?>><?= h($type['name'] . ' (' . $type['code'] . ')') ?></option><?php endforeach ?>
</select></label>
<label>Gruppe <select name="group_id"><option value="">Keine Gruppe</option>
<?php foreach ($settingLists['group'] as $group): ?><option value="<?= (int) $group['id'] ?>" <?= $selectedGroup === (string) $group['id'] ? 'selected' : '' ?>><?= h($group['name']) ?></option><?php endforeach ?>
</select></label>
<label>Kategorie <select name="category_id" id="device-category"><option value="">Keine Kategorie</option>
<?php if ($legacyCategory): ?><option value="legacy" <?= $selectedCategory === 'legacy' ? 'selected' : '' ?>><?= h($device['category'] . ' (bisheriger Wert)') ?></option><?php endif ?>
<?php foreach ($settingLists['category'] as $category): ?><option value="<?= (int) $category['id'] ?>" data-type-id="<?= (int) $category['type_id'] ?>" <?= $selectedCategory === (string) $category['id'] ? 'selected' : '' ?>><?= h($category['name']) ?></option><?php endforeach ?>
</select></label>
<?php
if (!$device):
    $mode = $submittedDevice && is_string($_POST['number_mode'] ?? null) ? $_POST['number_mode'] : 'automatic';
?>
<fieldset><legend>Inventarnummer vergeben</legend>
<label><input type="radio" name="number_mode" value="automatic" <?= $mode === 'automatic' ? 'checked' : '' ?>> Automatisch</label>
<label><input type="radio" name="number_mode" value="manual" <?= $mode === 'manual' ? 'checked' : '' ?>> Manuell</label>
<p>Für die automatische Vergabe Bereich und Kategorie auswählen; die Charge ist optional.
Für Zubehör kann die Inventarnummer eines vorhandenen PCs desselben Bereichs oder Raums und derselben Charge angegeben werden.
Das Feld „Inventarnummer“ wird nur bei manueller Vergabe berücksichtigt.</p>
<?php foreach (['Charge' => 3, 'Arbeitsplatz' => 100] as $name => $maximum):
    $value = $submittedDevice && is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
?>
<label><?= h($name === 'Arbeitsplatz' ? 'Inventarnummer des zugehörigen PCs (optional)' : $name) ?>
<input name="<?= h($name) ?>" maxlength="<?= $maximum ?>" value="<?= h($value) ?>" <?= $name === 'Arbeitsplatz' ? 'list="workplaces"' : '' ?>></label>
<?php endforeach ?></fieldset>
<datalist id="workplaces"><?php foreach ($workplaces as $workplace): ?>
<option value="<?= h($workplace) ?>"></option><?php endforeach ?></datalist>
<?php endif;
$deviceFields = [
    'Inventarnummer' => ['inventory_number', 100, 'text'],
    'Bezeichnung' => ['designation', 150, 'text'],
    'Produktname' => ['product_name', 150, 'text'],
    'Seriennummer' => ['serial_number', 150, 'text'],
    'Modell' => ['model', 150, 'text'],
    'Hersteller' => ['manufacturer', 150, 'text'],
    'Standort' => ['location', 150, 'text'],
    'Kaufdatum' => ['purchase_date', 10, 'date'],
    'Kaufpreis' => ['purchase_price', 13, 'price'],
    'Grafikkarte' => ['graphics_card', 150, 'text'],
    'Prozessor' => ['processor', 150, 'text'],
    'Speicher' => ['storage_capacity', 150, 'text'],
    'RAM' => ['ram', 150, 'text'],
    'OS' => ['operating_system', 150, 'text'],
    'Bemerkung' => ['notes', 2000, 'textarea'],
    'Zustand' => ['device_condition', 100, 'text'],
];
foreach ($deviceFields as $label => [$key, $maximum, $type]):
    $fromPost = $submittedDevice && is_string($_POST[$label] ?? null);
    $value = (string) ($fromPost ? $_POST[$label] : ($device[$key] ?? ''));
    if ($type === 'price' && !$fromPost) {
        $value = str_replace('.', ',', $value);
    }
?>
<label><?= h($label === 'Bezeichnung' ? 'Zusätzliche Bezeichnung' : $label) ?>
<?php if ($type === 'textarea'): ?>
<textarea name="<?= h($label) ?>" maxlength="<?= $maximum ?>" rows="3"><?= h($value) ?></textarea>
<?php else: ?>
<input name="<?= h($label) ?>" type="<?= $type === 'date' ? 'date' : 'text' ?>" value="<?= h($value) ?>"
    <?= $type === 'price' ? 'inputmode="decimal"' : '' ?> <?= $type === 'date' ? '' : 'maxlength="' . $maximum . '"' ?>
    <?= $key === 'inventory_number' ? 'id="inventory-number"' : '' ?>
    <?= $key === 'inventory_number' && $device ? 'required' : '' ?>>
<?php endif ?></label>
<?php endforeach ?>
<button>Speichern</button></form>
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
<?php $overviewFields = [
    'Bereich' => 'area_name', 'Typ' => 'type_name', 'Gruppe' => 'group_name', 'Kategorie' => 'category',
    'Zusätzliche Bezeichnung' => 'designation', 'Produktname' => 'product_name',
    'Seriennummer' => 'serial_number', 'Modell' => 'model', 'Hersteller' => 'manufacturer', 'Standort' => 'location',
    'Kaufdatum' => 'purchase_date', 'Kaufpreis' => 'purchase_price', 'Grafikkarte' => 'graphics_card',
    'Prozessor' => 'processor', 'Speicher' => 'storage_capacity', 'RAM' => 'ram', 'OS' => 'operating_system',
    'Bemerkung' => 'notes', 'Zustand' => 'device_condition',
]; ?>
<h2>Geräteübersicht</h2><table><thead><tr><th>Inventarnummer</th>
<?php foreach ($overviewFields as $label => $key): ?><th><?= h($label) ?></th><?php endforeach ?><th>Status</th></tr></thead><tbody>
<?php foreach ($devices as $row): ?><tr><td><a class="button" href="/?id=<?= (int) $row['id'] ?>"><?= h($row['inventory_number']) ?></a></td>
<?php foreach ($overviewFields as $key): $value = (string) ($row[$key] ?? ''); ?>
<td><?= h($value === '' ? '–' : ($key === 'purchase_price' ? str_replace('.', ',', $value) : $value)) ?></td>
<?php endforeach ?><td><?= $row['assigned'] ? 'Ausgegeben' : 'Am Standort / verfügbar' ?></td></tr><?php endforeach ?>
</tbody></table><?php if (!$devices): ?><p>Noch keine Geräte erfasst.</p><?php endif ?>
<?php endif ?>
<?php endif ?></main></body></html>
