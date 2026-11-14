<?php

declare(strict_types=1);

function validateUserCredentials(mixed $username, mixed $password): void
{
    if (!is_string($username) || !preg_match('/\A[a-zA-Z0-9._-]{1,100}\z/', $username)) {
        throw new InvalidArgumentException('Der Benutzername darf nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich enthalten.');
    }
    validatePassword($password);
}

function validatePassword(mixed $password): void
{
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
        throw new InvalidArgumentException('Das Passwort muss zwischen 12 und 72 Bytes lang sein.');
    }
}

function passwordVersionMatches(string $passwordHash, mixed $sessionVersion): bool
{
    return is_string($sessionVersion) && hash_equals(hash('sha256', $passwordHash), $sessionVersion);
}

function createUser(PDO $database, mixed $username, mixed $password, bool $canManageUsers, bool $canManageHandover): void
{
    validateUserCredentials($username, $password);
    $statement = $database->prepare(
        'INSERT INTO app_users (username, password_hash, can_manage_users, can_manage_handover) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $canManageUsers ? 1 : 0,
        $canManageHandover ? 1 : 0,
    ]);
}

function changeOwnPassword(PDO $database, int $userId, mixed $current, mixed $new, mixed $confirmation): string
{
    if (!is_string($current) || strlen($current) > 72) {
        throw new InvalidArgumentException('Das bisherige Passwort ist ungültig.');
    }
    if (!is_string($new) || !is_string($confirmation) || !hash_equals($new, $confirmation)) {
        throw new InvalidArgumentException('Die neuen Passwörter stimmen nicht überein.');
    }
    validatePassword($new);
    $attemptKey = 'password-change:' . $userId;
    $attempts = $database->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $attempts->execute([$attemptKey]);
    if ((int) $attempts->fetchColumn() >= 5) {
        throw new InvalidArgumentException('Zu viele Fehlversuche. Bitte warte 15 Minuten und versuche es erneut.');
    }
    $query = $database->prepare('SELECT password_hash FROM app_users WHERE id = ? AND active = 1');
    $query->execute([$userId]);
    $oldHash = $query->fetchColumn();
    if (!is_string($oldHash) || !password_verify($current, $oldHash)) {
        $database->prepare('INSERT INTO login_attempts (username) VALUES (?)')->execute([$attemptKey]);
        throw new InvalidArgumentException('Das bisherige Passwort ist ungültig.');
    }
    $database->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$attemptKey]);
    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $update = $database->prepare(
        'UPDATE app_users SET password_hash = ? WHERE id = ? AND active = 1 AND BINARY password_hash = BINARY ?'
    );
    $update->execute([$newHash, $userId, $oldHash]);
    if ($update->rowCount() !== 1) {
        throw new InvalidArgumentException('Das Konto wurde zwischenzeitlich geändert. Bitte erneut anmelden.');
    }
    return $newHash;
}
