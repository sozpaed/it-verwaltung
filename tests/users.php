<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/users.php';

function expectUser(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectUserException(string $class, callable $action): void
{
    try {
        $action();
    } catch (Throwable $exception) {
        expectUser(get_class($exception) === $class, 'Unerwarteter Ausnahmetyp: ' . get_class($exception));
        return;
    }
    throw new RuntimeException('Die erwartete Ausnahme ist ausgeblieben.');
}

$db = database();
$name = 'test-' . bin2hex(random_bytes(12));
$password = 'test-' . bin2hex(random_bytes(12));
$db->beginTransaction();
try {
    foreach ([['invalid name', $password], [$name, 'too-short']] as [$invalidName, $invalidPassword]) {
        try {
            createUser($db, $invalidName, $invalidPassword, false, false);
            throw new RuntimeException('Ungültige Kontodaten wurden akzeptiert.');
        } catch (InvalidArgumentException $exception) {
            // Expected validation failure.
        }
    }
    createUser($db, $name, $password, true, false);
    $query = $db->prepare('SELECT id, password_hash, can_manage_users, can_manage_handover FROM app_users WHERE username = ?');
    $query->execute([$name]);
    $user = $query->fetch();
    expectUser($user !== false && password_verify($password, $user['password_hash']), 'Passworthash ungültig.');
    expectUser((bool) $user['can_manage_users'] && !(bool) $user['can_manage_handover'], 'Berechtigungen nicht getrennt.');
    try {
        createUser($db, $name, $password, false, true);
        throw new RuntimeException('Doppelter Benutzername wurde akzeptiert.');
    } catch (PDOException $exception) {
        expectUser($exception->getCode() === '23000', 'Unerwarteter Datenbankfehler.');
    }
    $id = (int) $user['id'];
    expectUser(canManageInventorySettings(['can_manage_users' => 1]), 'Administratoren fehlt der Einstellungszugriff.');
    expectUser(canManageInventorySettings(['can_manage_inventory_settings' => 1]), 'Das separate Recht wird nicht erkannt.');
    expectUser(!canManageInventorySettings(['can_manage_users' => '0', 'can_manage_inventory_settings' => '0']),
        'Unberechtigter Einstellungszugriff.');
    foreach ([[0, $id, true], [$id, -1, true], [(string) $id, $id, true], [$id, $id, 1]] as [$actorId, $userId, $allowed]) {
        expectUserException(InvalidArgumentException::class, fn() => setInventorySettingsPermission($db, $actorId, $userId, $allowed));
    }
    $columnCheck = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $columnCheck->execute(['app_users', 'can_manage_inventory_settings']);
    if ((int) $columnCheck->fetchColumn() === 1) {
        $otherName = 'test-' . bin2hex(random_bytes(12));
        $disabledName = 'test-' . bin2hex(random_bytes(12));
        createUser($db, $otherName, $password, false, true, true);
        createUser($db, $disabledName, $password, true, false);
        $permissions = $db->prepare(
            'SELECT id, password_hash, can_manage_users, can_manage_handover, can_manage_inventory_settings, active
             FROM app_users WHERE username = ?'
        );
        $permissions->execute([$name]);
        $admin = $permissions->fetch();
        $permissions->execute([$otherName]);
        $other = $permissions->fetch();
        $permissions->execute([$disabledName]);
        $disabled = $permissions->fetch();
        expectUser(!(bool) $admin['can_manage_inventory_settings'] && (bool) $other['can_manage_inventory_settings'],
            'Das Recht wird beim Anlegen nicht korrekt gesetzt.');
        $otherId = (int) $other['id'];
        $disabledId = (int) $disabled['id'];
        $db->prepare('UPDATE app_users SET active = 0 WHERE id = ?')->execute([$disabledId]);
        expectUserException(RuntimeException::class, fn() => setInventorySettingsPermission($db, $otherId, $id, true));
        expectUserException(RuntimeException::class, fn() => setInventorySettingsPermission($db, $disabledId, $otherId, false));
        $missingId = PHP_INT_MAX;
        $missing = $db->prepare('SELECT 1 FROM app_users WHERE id = ?');
        $missing->execute([$missingId]);
        expectUser(!$missing->fetchColumn(), 'Die gewählte ID für den Negativtest ist bereits vergeben.');
        expectUserException(InvalidArgumentException::class, fn() => setInventorySettingsPermission($db, $id, $missingId, true));
        setInventorySettingsPermission($db, $id, $otherId, false);
        setInventorySettingsPermission($db, $id, $otherId, false);
        $permissions->execute([$otherName]);
        $revoked = $permissions->fetch();
        expectUser(!(bool) $revoked['can_manage_inventory_settings'], 'Das Recht wurde nicht entzogen.');
        foreach (['password_hash', 'can_manage_users', 'can_manage_handover', 'active'] as $field) {
            expectUser($revoked[$field] === $other[$field], 'Ein anderes Kontofeld wurde verändert: ' . $field);
        }
        setInventorySettingsPermission($db, $id, $otherId, true);
        $permissions->execute([$otherName]);
        $granted = $permissions->fetch();
        expectUser((bool) $granted['can_manage_inventory_settings'], 'Das Recht wurde nicht vergeben.');
        foreach (['password_hash', 'can_manage_users', 'can_manage_handover', 'active'] as $field) {
            expectUser($granted[$field] === $other[$field], 'Ein anderes Kontofeld wurde verändert: ' . $field);
        }
        $permissions->execute([$name]);
        expectUser(!(bool) $permissions->fetch()['can_manage_inventory_settings'], 'Unberechtigte Änderung wurde ausgeführt.');
        echo "Inventareinstellungs-Berechtigungen erfolgreich getestet.\n";
    } else {
        echo "Inventareinstellungs-Datenbanktests übersprungen: Spalte noch nicht vorhanden.\n";
    }
    $newPassword = 'new-' . bin2hex(random_bytes(12));
    foreach ([
        ['incorrect-password', $newPassword, $newPassword],
        [$password, $newPassword, 'different-password'],
        [$password, 'short', 'short'],
    ] as [$current, $new, $confirmation]) {
        try {
            changeOwnPassword($db, $id, $current, $new, $confirmation);
            throw new RuntimeException('Ungültige Passwortänderung wurde akzeptiert.');
        } catch (InvalidArgumentException $exception) {
            // Expected validation failure.
        }
    }
    $query->execute([$name]);
    $unchanged = $query->fetch();
    expectUser(password_verify($password, $unchanged['password_hash']), 'Das ursprüngliche Passwort wurde verändert.');
    $oldSession = hash('sha256', $user['password_hash']);
    $newHash = changeOwnPassword($db, $id, $password, $newPassword, $newPassword);
    $query->execute([$name]);
    $changed = $query->fetch();
    expectUser(password_verify($newPassword, $changed['password_hash']) && !password_verify($password, $changed['password_hash']),
        'Das neue Passwort wurde nicht übernommen.');
    expectUser(!passwordVersionMatches($changed['password_hash'], $oldSession), 'Eine alte Sitzung blieb gültig.');
    expectUser(passwordVersionMatches($changed['password_hash'], hash('sha256', $newHash)), 'Die aktuelle Sitzung ist ungültig.');
    expectUser(!passwordVersionMatches($changed['password_hash'], null), 'Eine Sitzung ohne Passwortversion wurde akzeptiert.');
    expectUser((bool) $changed['can_manage_users'] && !(bool) $changed['can_manage_handover'], 'Berechtigungen wurden verändert.');
    for ($i = 0; $i < 5; $i++) {
        try {
            changeOwnPassword($db, $id, 'incorrect-password', $password, $password);
            throw new RuntimeException('Falsches Passwort wurde akzeptiert.');
        } catch (InvalidArgumentException $exception) {
            expectUser($exception->getMessage() === 'Das bisherige Passwort ist ungültig.', 'Unerwartete Fehlermeldung.');
        }
    }
    try {
        changeOwnPassword($db, $id, $newPassword, $password, $password);
        throw new RuntimeException('Die Passwortänderung wurde nicht vorübergehend gesperrt.');
    } catch (InvalidArgumentException $exception) {
        expectUser(str_contains($exception->getMessage(), 'Zu viele Fehlversuche'), 'Falsche Sperrmeldung.');
    }
    $query->execute([$name]);
    expectUser(password_verify($newPassword, $query->fetch()['password_hash']), 'Das Passwort änderte sich trotz Sperre.');
    echo "Benutzerkontotests erfolgreich.\n";
} finally {
    $db->rollBack();
}
