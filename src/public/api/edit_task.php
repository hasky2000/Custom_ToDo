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

    if (!is_array($input)) {
        respond(['error' => 'JSONオブジェクトを送信してください。'], 400);
    }

    $id = $input['id'] ?? null;
    if (!is_int($id) || $id <= 0) {
        respond(['error' => '正しいTODO IDを指定してください。'], 422);
    }

    require_once dirname(__DIR__, 2) . '/app/task_input.php';
    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT task_type, starts_at, scheduled_at FROM tasks WHERE id = :id AND user_id = :user_id AND completed = FALSE FOR UPDATE');
    $statement->execute([':id' => $id, ':user_id' => $userId]);
    $existingTask = $statement->fetch();
    if ($existingTask === false) {
        $pdo->rollBack();
        respond(['error' => '編集できるTODOが見つかりません。'], 404);
    }
    $taskInput = normalizeTaskInput($input, $existingTask);
    $title = $taskInput['title'];
    $content = $taskInput['content'];
    $scheduledAt = $taskInput['scheduled_at'];

    $statement = $pdo->prepare(
        'UPDATE tasks
         SET title = :title,
             content = :content,
             task_type = :task_type,
             starts_at = :starts_at,
             scheduled_at = :scheduled_at,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id AND completed = FALSE
         RETURNING id, title, content, completed, progress, task_type, starts_at, scheduled_at, created_at, updated_at'
    );
    $statement->execute([
        ':id' => $id,
        ':user_id' => $userId,
        ':title' => $title,
        ':content' => $content,
        ':task_type' => $taskInput['task_type'],
        ':starts_at' => $taskInput['starts_at'],
        ':scheduled_at' => $scheduledAt,
    ]);
    $updatedTask = $statement->fetch();

    if ($updatedTask === false) {
        respond(['error' => '編集できるTODOが見つかりません。'], 404);
    }

    $pdo->commit();
    respond(['data' => $updatedTask], 200);
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => $exception->getMessage()], 422);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($exception->getMessage());
    respond(['error' => 'TODOを編集できませんでした。'], 500);
}
