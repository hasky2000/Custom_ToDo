<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/app/session.php';
startAppSession();
$userId = currentUserId();

if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare('SELECT id, email FROM users WHERE id = :id');
    $statement->execute([':id' => $userId]);
    $user = $statement->fetch();

    if ($user === false) {
        http_response_code(401);
        echo json_encode(['error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['data' => $user], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'ログイン状態を確認できませんでした。'], JSON_UNESCAPED_UNICODE);
}
