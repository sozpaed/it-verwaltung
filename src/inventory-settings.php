<?php

declare(strict_types=1);

function settingKinds(): array
{
    return ['area' => 'Bereich', 'type' => 'Typ', 'group' => 'Gruppe', 'category' => 'Kategorie'];
}

function settingId(mixed $value, bool $required = false): ?int
{
    if ($value === null || $value === '') {
        if ($required) {
            throw new InvalidArgumentException('Bitte eine Auswahl treffen.');
        }
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('Ungültige Auswahl.');
    }
    return $id;
}

function inventorySetting(PDO $db, string $kind, ?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    $query = $db->prepare('SELECT * FROM inventory_settings WHERE kind = ? AND id = ?');
    $query->execute([$kind, $id]);
    $row = $query->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Die ausgewählte Inventareinstellung existiert nicht.');
    }
    return $row;
}

function inventorySettings(PDO $db, string $kind): array
{
    if (!isset(settingKinds()[$kind])) {
        throw new InvalidArgumentException('Ungültige Art der Inventareinstellung.');
    }
    $query = $db->prepare('SELECT * FROM inventory_settings WHERE kind = ? ORDER BY name');
    $query->execute([$kind]);
    return $query->fetchAll();
}

function saveInventorySetting(PDO $db, string $kind, array $input, ?int $id): void
{
    if (!isset(settingKinds()[$kind])) {
        throw new InvalidArgumentException('Ungültige Art der Inventareinstellung.');
    }
    $name = field($input, 'name', 100);
    $code = null;
    if ($kind === 'area' || $kind === 'type') {
        $code = strtoupper(field($input, 'code', 12));
        $pattern = $kind === 'area' ? '/\A[A-Z0-9]{2,12}\z/' : '/\A[A-Z]{2}\z/';
        if (!preg_match($pattern, $code)) {
            throw new InvalidArgumentException($kind === 'area'
                ? 'Das Bereichskürzel muss aus 2 bis 12 Buchstaben oder Ziffern bestehen.'
                : 'Das Typkürzel muss aus genau zwei Buchstaben bestehen.');
        }
    }
    $typeId = null;
    if ($kind === 'category') {
        $typeId = settingId($input['type_id'] ?? null, true);
        inventorySetting($db, 'type', $typeId);
    }
    if ($id === null) {
        $db->prepare('INSERT INTO inventory_settings (kind, name, code, type_id) VALUES (?, ?, ?, ?)')
            ->execute([$kind, $name, $code, $typeId]);
        return;
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $lock = $db->prepare('SELECT id FROM inventory_settings WHERE id = ? AND kind = ? FOR UPDATE');
        $lock->execute([$id, $kind]);
        if ($lock->fetchColumn() === false) {
            throw new InvalidArgumentException('Die ausgewählte Inventareinstellung existiert nicht.');
        }
        $db->prepare('UPDATE inventory_settings SET name = ?, code = ?, type_id = ? WHERE id = ? AND kind = ?')
            ->execute([$name, $code, $typeId, $id, $kind]);
        if ($kind === 'category') {
            $db->prepare('UPDATE devices SET category = ? WHERE inventory_category_id = ?')->execute([$name, $id]);
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function deleteInventorySetting(PDO $db, string $kind, int $id): void
{
    if (!isset(settingKinds()[$kind])) {
        throw new InvalidArgumentException('Ungültige Art der Inventareinstellung.');
    }
    $delete = $db->prepare('DELETE FROM inventory_settings WHERE id = ? AND kind = ?');
    $delete->execute([$id, $kind]);
    if ($delete->rowCount() !== 1) {
        throw new InvalidArgumentException('Die ausgewählte Inventareinstellung existiert nicht.');
    }
}

function configuredDeviceInput(PDO $db, array $input, ?array $device): array
{
    $area = inventorySetting($db, 'area', settingId($input['area_id'] ?? null));
    $group = inventorySetting($db, 'group', settingId($input['group_id'] ?? null));
    $categoryValue = $input['category_id'] ?? '';
    if ($categoryValue === 'legacy') {
        if ($device === null || $device['inventory_category_id'] !== null || $device['category'] === '') {
            throw new InvalidArgumentException('Ungültige bisherige Kategorie.');
        }
        $category = null;
        $categoryName = $device['category'];
    } else {
        $category = inventorySetting($db, 'category', settingId($categoryValue));
        $categoryName = $category['name'] ?? '';
    }
    return array_replace($input, [
        'inventory_area_id' => $area['id'] ?? null,
        'inventory_group_id' => $group['id'] ?? null,
        'inventory_category_id' => $category['id'] ?? null,
        'Kategorie' => $categoryName,
    ]);
}
