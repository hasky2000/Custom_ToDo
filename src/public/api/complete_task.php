<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $statusCode): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH') {
    header('Allow: PATCH');
    respond(['error' => 'PATCHメソッドを使用してください。'], 405);
}

require_once dirname(__DIR__, 2) . '/app/session.php';
startAppSession();
$userId = currentUserId();

if ($userId === null) {
    respond(['error' => 'ログインが必要です。'], 401);
}

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput ?: '', true, 512, JSON_THROW_ON_ERROR);
    $id = is_array($input) ? ($input['id'] ?? null) : null;

    if (!is_int($id) || $id <= 0) {
        respond(['error' => '正しいTODO IDを指定してください。'], 422);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare(
        'UPDATE tasks
         SET completed = TRUE,
             progress = 100,
             completed_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id AND completed = FALSE
         RETURNING id, title, content, completed, progress, scheduled_at, created_at, updated_at'
    );
    $statement->execute([':id' => $id, ':user_id' => $userId]);
    $completedTask = $statement->fetch();

    if ($completedTask === false) {
        respond(['error' => '完了できるTODOが見つかりません。'], 404);
    }

    respond(['data' => $completedTask], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => 'TODOを完了できませんでした。'], 500);
}
