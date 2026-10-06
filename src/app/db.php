<?php

declare(strict_types=1);

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$dbname = getenv('POSTGRES_DB');
$user = getenv('POSTGRES_USER');
$password = getenv('POSTGRES_PASSWORD');

if (
    $host === false ||
    $port === false ||
    $dbname === false ||
    $user === false ||
    $password === false
) {
    throw new RuntimeException('データベース接続用の環境変数が不足しています。');
}

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

return new PDO(
    $dsn,
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
?>