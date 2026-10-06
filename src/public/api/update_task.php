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
    $progress = is_array($input) ? ($input['progress'] ?? null) : null;

    if (!is_int($id) || $id <= 0) {
        respond(['error' => '正しいTODO IDを指定してください。'], 422);
    }

    if (!is_int($progress) || $progress < 0 || $progress > 100) {
        respond(['error' => '進捗率は0から100の整数で指定してください。'], 422);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare(
        'UPDATE tasks
         SET progress = :progress,
             completed = CASE WHEN :completion_progress = 100 THEN TRUE ELSE completed END,
             completed_at = CASE WHEN :archive_progress = 100 THEN CURRENT_TIMESTAMP ELSE completed_at END,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id AND completed = FALSE
         RETURNING id, title, content, completed, progress, scheduled_at, created_at, updated_at'
    );
    $statement->execute([
        ':id' => $id,
        ':user_id' => $userId,
        ':progress' => $progress,
        ':completion_progress' => $progress,
        ':archive_progress' => $progress,
    ]);
    $updatedTask = $statement->fetch();

    if ($updatedTask === false) {
        respond(['error' => '更新できるTODOが見つかりません。'], 404);
    }

    respond(['data' => $updatedTask], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => 'TODOを更新できませんでした。'], 500);
}
