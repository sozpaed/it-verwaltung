<?php

declare(strict_types=1);

function field(array $input, string $key, int $max, bool $required = true): string
{
    $value = $input[$key] ?? null;
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('Ungültiger Wert für ' . $key . '.');
    }
    $value = trim($value);
    if (($required && $value === '') || preg_match_all('/./us', $value) > $max) {
        throw new InvalidArgumentException('Bitte das Feld ' . $key . ' prüfen.');
    }
    return $value;
}

function dateField(array $input, string $key, bool $required = true): ?string
{
    $value = field($input, $key, 10, $required);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Ungültiges Datum: ' . $key . '.');
    }
    return $value;
}

function deviceValues(array $input): array
{
    return [
        'inventory_number' => field($input, 'Inventarnummer', 100),
        'category' => field($input, 'Geräteart', 100),
        'model' => field($input, 'Modell', 150),
        'serial_number' => field($input, 'Seriennummer', 150, false),
        'location' => field($input, 'Standort', 150),
        'device_condition' => field($input, 'Zustand', 100),
        'notes' => field($input, 'Notizen', 2000, false),
    ];
}

function saveDevice(PDO $database, array $input, ?int $id): void
{
    $values = deviceValues($input);
    if ($id === null) {
        $sql = 'INSERT INTO devices (inventory_number, category, model, serial_number, location, device_condition, notes)
                VALUES (:inventory_number, :category, :model, :serial_number, :location, :device_condition, :notes)';
    } else {
        $sql = 'UPDATE devices SET inventory_number = :inventory_number, category = :category, model = :model,
                serial_number = :serial_number, location = :location, device_condition = :device_condition, notes = :notes
                WHERE id = :id';
        $values['id'] = $id;
    }
    $database->prepare($sql)->execute($values);
}

function createHandover(PDO $database, int $deviceId, array $input): void
{
    $recipient = field($input, 'Schülerinnenkennung', 100);
    $class = field($input, 'Klasse', 50);
    $kind = field($input, 'Art', 20);
    if (!in_array($kind, ['loan', 'permanent'], true)) {
        throw new InvalidArgumentException('Ungültige Ausgabeart.');
    }
    $date = dateField($input, 'Ausgabedatum');
    $due = dateField($input, 'Rückgabefrist', false);
    if ($due !== null && ($kind !== 'loan' || $due < $date)) {
        throw new InvalidArgumentException('Die Rückgabefrist ist ungültig.');
    }
    $database->beginTransaction();
    try {
        $lock = $database->prepare('SELECT id FROM devices WHERE id = ? FOR UPDATE');
        $lock->execute([$deviceId]);
        if ($lock->fetchColumn() === false) {
            throw new InvalidArgumentException('Das Gerät existiert nicht.');
        }
        $active = $database->prepare('SELECT id FROM handovers WHERE device_id = ? AND returned_on IS NULL');
        $active->execute([$deviceId]);
        if ($active->fetchColumn() !== false) {
            throw new InvalidArgumentException('Das Gerät ist bereits ausgegeben.');
        }
        $database->prepare(
            'INSERT INTO handovers (device_id, recipient_id, recipient_class, kind, handed_over_on, due_on)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$deviceId, $recipient, $class, $kind, $date, $due]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function returnHandover(PDO $database, int $deviceId, array $input): void
{
    $returned = dateField($input, 'Rückgabedatum');
    $condition = field($input, 'Rückgabezustand', 100);
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'SELECT id, handed_over_on FROM handovers WHERE device_id = ? AND returned_on IS NULL FOR UPDATE'
        );
        $statement->execute([$deviceId]);
        $handover = $statement->fetch();
        if ($handover === false) {
            throw new InvalidArgumentException('Für dieses Gerät gibt es keine offene Ausgabe.');
        }
        if ($returned < $handover['handed_over_on']) {
            throw new InvalidArgumentException('Die Rückgabe liegt vor der Ausgabe.');
        }
        $database->prepare('UPDATE handovers SET returned_on = ?, return_condition = ? WHERE id = ?')
            ->execute([$returned, $condition, $handover['id']]);
        $database->prepare('UPDATE devices SET device_condition = ? WHERE id = ?')->execute([$condition, $deviceId]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}
