<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $statusCode): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond(['error' => 'GETメソッドを使用してください。'], 405);
}

require_once dirname(__DIR__, 2) . '/app/session.php';
startAppSession();
$userId = currentUserId();

if ($userId === null) {
    respond(['error' => 'ログインが必要です。'], 401);
}

try {
    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare(
        'SELECT id, title, content, completed, progress, scheduled_at, created_at, updated_at
         FROM tasks
         WHERE user_id = :user_id AND completed = TRUE
         ORDER BY updated_at DESC, id DESC'
    );
    $statement->execute([':user_id' => $userId]);

    respond(['data' => $statement->fetchAll()], 200);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => '完了済みTODOを取得できませんでした。'], 500);
}
