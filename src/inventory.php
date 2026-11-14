<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory-settings.php';

function field(array $input, string $key, int $max, bool $required = true): string
{
    $value = array_key_exists($key, $input) ? $input[$key] : ($required ? null : '');
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
    $price = field($input, 'Kaufpreis', 13, false);
    if ($price !== '' && !preg_match('/\A[0-9]{1,10}(?:[.,][0-9]{1,2})?\z/', $price)) {
        throw new InvalidArgumentException('Der Kaufpreis muss eine nicht negative Zahl mit höchstens zwei Nachkommastellen sein.');
    }

    return [
        'inventory_number' => field($input, 'Inventarnummer', 100),
        'designation' => field($input, 'Bezeichnung', 150, false),
        'product_name' => field($input, 'Produktname', 150, false),
        'category' => field($input, 'Kategorie', 100, false),
        'inventory_area_id' => settingId($input['inventory_area_id'] ?? null),
        'inventory_group_id' => settingId($input['inventory_group_id'] ?? null),
        'inventory_category_id' => settingId($input['inventory_category_id'] ?? null),
        'serial_number' => field($input, 'Seriennummer', 150, false),
        'model' => field($input, 'Modell', 150, false),
        'manufacturer' => field($input, 'Hersteller', 150, false),
        'location' => field($input, 'Standort', 150, false),
        'purchase_date' => dateField($input, 'Kaufdatum', false),
        'purchase_price' => $price === '' ? null : str_replace(',', '.', $price),
        'graphics_card' => field($input, 'Grafikkarte', 150, false),
        'processor' => field($input, 'Prozessor', 150, false),
        'storage_capacity' => field($input, 'Speicher', 150, false),
        'ram' => field($input, 'RAM', 150, false),
        'operating_system' => field($input, 'OS', 150, false),
        'notes' => field($input, 'Bemerkung', 2000, false),
        'device_condition' => field($input, 'Zustand', 100, false),
    ];
}

function saveDevice(PDO $database, array $input, ?int $id): void
{
    $values = deviceValues($input);
    if ($id === null) {
        $sql = 'INSERT INTO devices
                (inventory_number, designation, product_name, category, inventory_area_id, inventory_group_id, inventory_category_id,
                 serial_number, model, manufacturer, location,
                 purchase_date, purchase_price, graphics_card, processor, storage_capacity, ram, operating_system, notes, device_condition)
                VALUES (:inventory_number, :designation, :product_name, :category, :inventory_area_id, :inventory_group_id, :inventory_category_id,
                        :serial_number, :model, :manufacturer, :location,
                        :purchase_date, :purchase_price, :graphics_card, :processor, :storage_capacity, :ram, :operating_system, :notes, :device_condition)';
    } else {
        $sql = 'UPDATE devices SET inventory_number = :inventory_number, designation = :designation, product_name = :product_name,
                category = :category, serial_number = :serial_number, model = :model, manufacturer = :manufacturer, location = :location,
                inventory_area_id = :inventory_area_id, inventory_group_id = :inventory_group_id, inventory_category_id = :inventory_category_id,
                purchase_date = :purchase_date, purchase_price = :purchase_price, graphics_card = :graphics_card, processor = :processor,
                storage_capacity = :storage_capacity, ram = :ram, operating_system = :operating_system, notes = :notes,
                device_condition = :device_condition
                WHERE id = :id';
        $values['id'] = $id;
    }
    $database->prepare($sql)->execute($values);
}

function saveDeviceWithGeneratedNumber(PDO $database, array $input): string
{
    $area = inventorySetting($database, 'area', settingId($input['inventory_area_id'] ?? null, true));
    $category = inventorySetting($database, 'category', settingId($input['inventory_category_id'] ?? null, true));
    $type = inventorySetting($database, 'type', settingId($category['type_id'], true));
    $prefix = $area['code'];
    $batch = field($input, 'Charge', 3, false);
    if ($batch !== '' && !preg_match('/\A[1-9][0-9]{0,2}\z/', $batch)) {
        throw new InvalidArgumentException('Die Charge muss eine positive Zahl ohne führende Null sein.');
    }
    $base = $prefix . $batch;
    $reference = strtoupper(field($input, 'Arbeitsplatz', 100, false));
    $positions = range(1, 99);
    if ($reference !== '') {
        if ($type['code'] === 'PC' || !preg_match('/\A' . preg_quote($base, '/') . 'PC([0-9]{2})\z/', $reference, $matches)
            || $matches[1] === '00') {
            throw new InvalidArgumentException('Bitte einen vorhandenen PC desselben Bereichs oder Raums und derselben Charge mit einer Nummer von 01 bis 99 angeben.');
        }
        $check = $database->prepare('SELECT id FROM devices WHERE inventory_number = ?');
        $check->execute([$reference]);
        if ($check->fetchColumn() === false) {
            throw new InvalidArgumentException('Der ausgewählte Arbeitsplatz wurde nicht gefunden.');
        }
        $positions = [(int) $matches[1]];
    }

    foreach ($positions as $position) {
        $number = $base . $type['code'] . sprintf('%02d', $position);
        $check = $database->prepare('SELECT id FROM devices WHERE inventory_number = ?');
        $check->execute([$number]);
        if ($check->fetchColumn() !== false) {
            continue;
        }
        try {
            saveDevice($database, array_replace($input, ['Inventarnummer' => $number]), null);
            return $number;
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
        }
    }
    throw new InvalidArgumentException($reference !== ''
        ? 'Für diesen Arbeitsplatz ist bereits ein Gerät dieses Typs vorhanden.'
        : 'Für diesen Bereich oder Raum, die Charge und den Gerätetyp sind alle Nummern von 01 bis 99 vergeben.');
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
