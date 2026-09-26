<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap (spec §76.5)
|--------------------------------------------------------------------------
|
| Tests run against real MySQL databases: a dedicated landlord test database
| and real tenant databases. The tenancy layer is never mocked away. This
| file makes sure the landlord test database exists before RefreshDatabase
| migrates it.
|
*/

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$database = getenv('DB_DATABASE') ?: 'tea_test_landlord';

if (! str_starts_with($database, 'tea_test_')) {
    fwrite(STDERR, "Refusing to run tests against a non-test database [{$database}].\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1', $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306'),
    (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root'),
    (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''),
);

$pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database));
