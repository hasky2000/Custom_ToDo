<?php

declare(strict_types=1);

// require元の入力パスワードやユーザー情報を上書きしないよう、DB専用の変数名にする。
$databaseHost = getenv('DB_HOST');
$databasePort = getenv('DB_PORT');
$databaseName = getenv('POSTGRES_DB');
$databaseUser = getenv('POSTGRES_USER');
$databasePassword = getenv('POSTGRES_PASSWORD');

if (
    $databaseHost === false ||
    $databasePort === false ||
    $databaseName === false ||
    $databaseUser === false ||
    $databasePassword === false
) {
    throw new RuntimeException('データベース接続用の環境変数が不足しています。');
}

$databaseDsn = "pgsql:host={$databaseHost};port={$databasePort};dbname={$databaseName}";

return new PDO(
    $databaseDsn,
    $databaseUser,
    $databasePassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
?>
