<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/schema.php';
require_once dirname(__DIR__) . '/src/users.php';

final class SetupCountStatement extends PDOStatement
{
    public function __construct(private int $count) {}

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->count;
    }
}

final class SetupDatabase extends PDO
{
    public array $executed = [];

    public function __construct(private int $tableCount) {}

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new SetupCountStatement($this->tableCount);
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        return 0;
    }
}

$schemaPath = dirname(__DIR__) . '/database/schema.sql';
$existing = new SetupDatabase(1);
try {
    initializeEmptyDatabase($existing, $schemaPath);
    throw new RuntimeException('Eine nicht leere Datenbank wurde akzeptiert.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'nicht leer') || $existing->executed !== []) {
        throw $exception;
    }
}
$empty = new SetupDatabase(0);
initializeEmptyDatabase($empty, $schemaPath);
if ($empty->executed === []) {
    throw new RuntimeException('Das Schema wurde für eine leere Datenbank nicht ausgeführt.');
}
foreach ([
    ['DB_HOST' => 'example.invalid', 'DB_PORT' => 3307, 'DB_NAME' => 'itdoku_dev', 'DB_USER' => 'itdoku_app', 'DB_PASSWORD' => 'test'],
    ['DB_HOST' => '127.0.0.1', 'DB_PORT' => 3307, 'DB_NAME' => 'itdoku_dev', 'DB_USER' => 'root', 'DB_PASSWORD' => 'test'],
] as $invalidConfig) {
    try {
        databaseWithConfiguration($invalidConfig);
        throw new LogicException('Eine unzulässige Datenbankverbindung wurde akzeptiert.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Ungültige lokale Datenbankkonfiguration.') {
            throw $exception;
        }
    }
}
foreach ([['not valid', 'long-enough-password'], ['valid-name', 'short']] as [$username, $password]) {
    try {
        validateUserCredentials($username, $password);
        throw new RuntimeException('Ungültige Administratordaten wurden akzeptiert.');
    } catch (InvalidArgumentException $exception) {
        // Expected validation failure.
    }
}
echo "Setup-Schutztests erfolgreich.\n";
