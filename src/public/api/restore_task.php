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
require_once dirname(__DIR__, 2) . '/app/task_order.php';
startAppSession();
$userId = currentUserId();

if ($userId === null) {
    respond(['error' => 'ログインが必要です。'], 401);
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    $id = is_array($input) ? ($input['id'] ?? null) : null;

    if (!is_int($id) || $id <= 0) {
        respond(['error' => '正しいTODO IDを指定してください。'], 422);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $pdo->beginTransaction();
    lockTaskOrder($pdo, $userId);
    $statement = $pdo->prepare(
        'UPDATE tasks
         SET completed = FALSE,
             progress = 0,
             completed_at = NULL,
             sort_order = :sort_order,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id AND completed = TRUE
         RETURNING id, title, content, completed, progress, scheduled_at, created_at, updated_at'
    );
    $statement->execute([
        ':id' => $id,
        ':user_id' => $userId,
        ':sort_order' => nextTaskOrder($pdo, $userId),
    ]);
    $task = $statement->fetch();

    if ($task === false) {
        $pdo->rollBack();
        respond(['error' => '復元できるTODOが見つかりません。'], 404);
    }

    $pdo->commit();
    respond(['data' => $task], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    respond(['error' => 'TODOを復元できませんでした。'], 500);
}
