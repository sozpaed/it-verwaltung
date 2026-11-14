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

function generated(PDO $db, array $input, array &$created): string
{
    $number = saveDeviceWithGeneratedNumber($db, $input);
    $created[(int) $db->lastInsertId()] = $number;
    return $number;
}

$db = database();
$number = 'TEST-' . bin2hex(random_bytes(12));
$prefix = 'T' . strtoupper(bin2hex(random_bytes(5)));
$deviceId = null;
$generatedDevices = [];
try {
    rejects(fn () => deviceValues([]));
    saveDevice($db, ['Inventarnummer' => $number], null);
    $deviceId = (int) $db->lastInsertId();
    $select = $db->prepare('SELECT * FROM devices WHERE id = ?');
    $select->execute([$deviceId]);
    $empty = $select->fetch();
    expect($empty['category'] === '' && $empty['location'] === ''
        && $empty['purchase_date'] === null && $empty['purchase_price'] === null, 'Optionale Angaben wurden nicht leer gespeichert.');

    $values = [
        'Inventarnummer' => $number, 'Bezeichnung' => 'Testgerät', 'Produktname' => 'Produkt',
        'Kategorie' => 'Notebook', 'Seriennummer' => 'SER-123', 'Modell' => 'Modell', 'Hersteller' => 'Hersteller',
        'Standort' => 'Computerraum', 'Kaufdatum' => '2024-02-29', 'Kaufpreis' => '1234,50',
        'Grafikkarte' => 'Grafik', 'Prozessor' => 'CPU', 'Speicher' => '512 GB SSD', 'RAM' => '16 GB',
        'OS' => 'Test-OS', 'Bemerkung' => 'Testbemerkung', 'Zustand' => 'Gut',
    ];
    foreach (['-1', '1,234', '10000000000', '1 EUR'] as $invalidPrice) {
        rejects(fn () => deviceValues(array_replace($values, ['Kaufpreis' => $invalidPrice])));
    }
    rejects(fn () => deviceValues(array_replace($values, ['Kaufdatum' => '2025-02-29'])));
    saveDevice($db, $values, $deviceId);
    $select->execute([$deviceId]);
    $stored = $select->fetch();
    $expected = [
        'designation' => 'Testgerät', 'product_name' => 'Produkt', 'category' => 'Notebook',
        'serial_number' => 'SER-123', 'model' => 'Modell', 'manufacturer' => 'Hersteller',
        'location' => 'Computerraum', 'purchase_date' => '2024-02-29', 'purchase_price' => '1234.50',
        'graphics_card' => 'Grafik', 'processor' => 'CPU', 'storage_capacity' => '512 GB SSD', 'ram' => '16 GB',
        'operating_system' => 'Test-OS', 'notes' => 'Testbemerkung', 'device_condition' => 'Gut',
    ];
    foreach ($expected as $column => $value) {
        expect($stored[$column] === $value, 'Gerätefeld nicht korrekt gespeichert: ' . $column);
    }
    saveDevice($db, array_replace($values, ['Kaufdatum' => '', 'Kaufpreis' => '']), $deviceId);
    $select->execute([$deviceId]);
    $cleared = $select->fetch();
    expect($cleared['purchase_date'] === null && $cleared['purchase_price'] === null, 'Optionale Kaufangaben konnten nicht geleert werden.');
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

    $db->beginTransaction();
    saveInventorySetting($db, 'area', ['name' => 'Test ' . $prefix, 'code' => $prefix], null);
    $areaId = (int) $db->lastInsertId();
    saveInventorySetting($db, 'group', ['name' => 'Testgruppe ' . $prefix], null);
    $groupId = (int) $db->lastInsertId();
    $typeIds = [];
    foreach (['PC', 'MO', 'TB', 'TV', 'MA'] as $code) {
        $findType = $db->prepare("SELECT id FROM inventory_settings WHERE kind = 'type' AND code = ?");
        $findType->execute([$code]);
        $typeId = $findType->fetchColumn();
        if ($typeId === false) {
            saveInventorySetting($db, 'type', ['name' => 'Testtyp ' . $prefix . $code, 'code' => $code], null);
            $typeId = $db->lastInsertId();
        }
        $typeIds[$code] = (int) $typeId;
    }
    $categories = [];
    foreach (['iMac' => 'PC', 'Laptop' => 'PC', 'Monitor' => 'MO', 'iPad' => 'TB', 'Fernseher' => 'TV', 'Maus' => 'MA'] as $name => $code) {
        saveInventorySetting($db, 'category', ['name' => $prefix . $name, 'type_id' => (string) $typeIds[$code]], null);
        $categories[$name] = (int) $db->lastInsertId();
    }
    $makeInput = static fn (string $category): array => configuredDeviceInput($db, [
        'area_id' => (string) $areaId, 'group_id' => (string) $groupId, 'category_id' => (string) $categories[$category],
        'Charge' => '1',
    ], null);
    $auto = $makeInput('iMac');
    $pc = generated($db, $auto, $generatedDevices);
    expect($pc === $prefix . '1PC01', 'Erster iMac muss die PC-Nummer 01 erhalten.');
    $select->execute([(int) $db->lastInsertId()]);
    $configured = $select->fetch();
    expect((int) $configured['inventory_area_id'] === $areaId && (int) $configured['inventory_group_id'] === $groupId
        && (int) $configured['inventory_category_id'] === $categories['iMac'], 'Ausgewählte Inventareinstellungen fehlen.');
    expect(generated($db, $makeInput('Laptop'), $generatedDevices) === $prefix . '1PC02',
        'PC-Kategorien müssen gemeinsam fortlaufend nummeriert werden.');
    $accessory = array_replace($makeInput('Monitor'), ['Arbeitsplatz' => $pc]);
    expect(generated($db, $accessory, $generatedDevices) === $prefix . '1MO01', 'Zubehör muss die Arbeitsplatznummer übernehmen.');
    rejects(fn () => saveDeviceWithGeneratedNumber($db, $accessory));
    rejects(fn () => saveDeviceWithGeneratedNumber($db, array_replace($accessory, ['Charge' => '2'])));
    rejects(fn () => saveDeviceWithGeneratedNumber($db, array_replace($accessory, ['Arbeitsplatz' => $prefix . '1PC99'])));
    rejects(fn () => saveDeviceWithGeneratedNumber($db, array_replace($accessory, ['Arbeitsplatz' => $prefix . '1PC00'])));
    rejects(fn () => saveDeviceWithGeneratedNumber($db, array_replace($makeInput('iMac'), ['Arbeitsplatz' => $pc])));
    rejects(fn () => configuredDeviceInput($db, ['category_id' => (string) $areaId], null));
    rejects(fn () => configuredDeviceInput($db, ['category_id' => 'legacy'], null));
    $legacy = configuredDeviceInput($db, ['category_id' => 'legacy'], ['inventory_category_id' => null, 'category' => 'Bisherig']);
    expect($legacy['Kategorie'] === 'Bisherig' && $legacy['inventory_category_id'] === null,
        'Eine bisherige Freitextkategorie muss erhalten bleiben.');
    rejects(fn () => saveDeviceWithGeneratedNumber($db, array_replace($auto, ['Charge' => '01'])));
    rejects(fn () => saveInventorySetting($db, 'area', ['name' => 'Ungültig', 'code' => 'X-1'], null));
    rejects(fn () => saveInventorySetting($db, 'category', ['name' => 'Ungültig', 'type_id' => (string) $areaId], null));
    expect(generated($db, array_replace($makeInput('Fernseher'), ['Charge' => '']), $generatedDevices)
        === $prefix . 'TV01', 'Fernseher ohne Charge muss das Kürzel TV erhalten.');
    expect(generated($db, $makeInput('iPad'), $generatedDevices) === $prefix . '1TB01',
        'Tablets müssen das Kürzel TB erhalten.');
    expect(generated($db, $makeInput('Monitor'), $generatedDevices) === $prefix . '1MO02',
        'Bereits vergebene Positionen müssen übersprungen werden.');

    $findType = $db->prepare("SELECT id FROM inventory_settings WHERE kind = 'type' AND code = ?");
    $customCode = null;
    foreach (range('A', 'Z') as $first) {
        foreach (range('A', 'Z') as $second) {
            $findType->execute([$first . $second]);
            if ($findType->fetchColumn() === false) {
                $customCode = $first . $second;
                break 2;
            }
        }
    }
    if ($customCode === null) {
        throw new RuntimeException('Kein freies Typkürzel für den Test vorhanden.');
    }
    saveInventorySetting($db, 'type', ['name' => 'Testtyp ' . $prefix, 'code' => $customCode], null);
    $customType = (int) $db->lastInsertId();
    saveInventorySetting($db, 'category', ['name' => $prefix . 'Spezial', 'type_id' => (string) $customType], null);
    $customCategory = (int) $db->lastInsertId();
    $custom = configuredDeviceInput($db, [
        'area_id' => (string) $areaId, 'category_id' => (string) $customCategory, 'Charge' => '1',
    ], null);
    expect(generated($db, $custom, $generatedDevices) === $prefix . '1' . $customCode . '01',
        'Eine neue Kategorie muss das Kürzel ihres konfigurierten Typs verwenden.');
    try {
        deleteInventorySetting($db, 'type', $customType);
        throw new RuntimeException('Ein verwendeter Typ wurde gelöscht.');
    } catch (PDOException $exception) {
        expect($exception->getCode() === '23000', 'Unerwarteter Fehler beim Löschen eines verwendeten Typs.');
    }

    $full = array_replace($makeInput('Maus'), ['Charge' => '2']);
    for ($position = 1; $position <= 99; $position++) {
        expect(generated($db, $full, $generatedDevices) === $prefix . '2MA' . sprintf('%02d', $position),
            'Die automatischen Nummern müssen von 01 bis 99 vergeben werden.');
    }
    rejects(fn () => saveDeviceWithGeneratedNumber($db, $full));
    saveInventorySetting($db, 'category', ['name' => $prefix . 'iMac neu', 'type_id' => (string) $typeIds['PC']], $categories['iMac']);
    $select->execute([(int) array_search($pc, $generatedDevices, true)]);
    $renamed = $select->fetch();
    expect($renamed['category'] === $prefix . 'iMac neu' && $renamed['inventory_number'] === $pc,
        'Umbenennen einer Kategorie muss Geräte aktualisieren, ohne Inventarnummern zu ändern.');
    try {
        deleteInventorySetting($db, 'group', $groupId);
        throw new RuntimeException('Eine verwendete Gruppe wurde gelöscht.');
    } catch (PDOException $exception) {
        expect($exception->getCode() === '23000', 'Unerwarteter Fehler beim Löschen einer verwendeten Gruppe.');
    }
    echo "Inventartests erfolgreich.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($deviceId !== null) {
        $db->prepare('DELETE FROM handovers WHERE device_id = ?')->execute([$deviceId]);
        $db->prepare('DELETE FROM devices WHERE id = ? AND inventory_number = ?')->execute([$deviceId, $number]);
    }
}
