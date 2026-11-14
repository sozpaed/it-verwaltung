<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/inventory.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $action): void
{
    try {
        $action();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    throw new RuntimeException('Ungültige Eingabe wurde akzeptiert.');
}

$db = database();
$number = 'TEST-' . bin2hex(random_bytes(12));
$deviceId = null;
try {
    saveDevice($db, [
        'Inventarnummer' => $number, 'Geräteart' => 'Notebook', 'Modell' => 'Testmodell',
        'Seriennummer' => '', 'Standort' => 'Computerraum', 'Zustand' => 'Gut', 'Notizen' => '',
    ], null);
    $deviceId = (int) $db->lastInsertId();
    $loan = [
        'Schülerinnenkennung' => 'TEST-123', 'Klasse' => '5a', 'Art' => 'loan',
        'Ausgabedatum' => '2026-01-10', 'Rückgabefrist' => '2026-02-10',
    ];
    rejects(fn () => createHandover($db, $deviceId, array_replace($loan, ['Rückgabefrist' => '2026-01-09'])));
    rejects(fn () => createHandover($db, $deviceId, array_replace($loan, ['Ausgabedatum' => '2026-02-30'])));
    createHandover($db, $deviceId, $loan);
    rejects(fn () => createHandover($db, $deviceId, $loan));
    rejects(fn () => returnHandover($db, $deviceId, ['Rückgabedatum' => '2026-01-09', 'Rückgabezustand' => 'Gut']));
    returnHandover($db, $deviceId, ['Rückgabedatum' => '2026-01-15', 'Rückgabezustand' => 'Gebraucht']);
    $condition = $db->prepare('SELECT device_condition FROM devices WHERE id = ?');
    $condition->execute([$deviceId]);
    expect($condition->fetchColumn() === 'Gebraucht', 'Der Rückgabezustand wurde nicht übernommen.');
    rejects(fn () => returnHandover($db, $deviceId, ['Rückgabedatum' => '2026-01-16', 'Rückgabezustand' => 'Gut']));
    rejects(fn () => createHandover($db, $deviceId, array_replace($loan, ['Art' => 'permanent'])));
    createHandover($db, $deviceId, array_replace($loan, ['Art' => 'permanent', 'Rückgabefrist' => '']));
    $count = $db->prepare('SELECT COUNT(*) FROM handovers WHERE device_id = ? AND returned_on IS NULL');
    $count->execute([$deviceId]);
    expect((int) $count->fetchColumn() === 1, 'Genau eine offene Ausgabe erwartet.');
    echo "Inventartests erfolgreich.\n";
} finally {
    if ($deviceId !== null) {
        $db->prepare('DELETE FROM handovers WHERE device_id = ?')->execute([$deviceId]);
        $db->prepare('DELETE FROM devices WHERE id = ? AND inventory_number = ?')->execute([$deviceId, $number]);
    }
}
