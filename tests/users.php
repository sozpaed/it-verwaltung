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
