<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $statusCode): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(['error' => 'POSTメソッドを使用してください。'], 405);
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

    require_once dirname(__DIR__, 2) . '/app/task_input.php';
    $taskInput = normalizeTaskInput($input);
    $title = $taskInput['title'];
    $content = $taskInput['content'];
    $scheduledAt = $taskInput['scheduled_at'];

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    require_once dirname(__DIR__, 2) . '/app/task_order.php';
    $pdo->beginTransaction();
    lockTaskOrder($pdo, $userId);

    $sql = 'INSERT INTO tasks (user_id, title, content, task_type, starts_at, scheduled_at, sort_order)
            VALUES (:user_id, :title, :content, :task_type, :starts_at, :scheduled_at, :sort_order)
            RETURNING id, title, content, completed, progress, task_type, starts_at, scheduled_at, created_at, updated_at';
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':content' => $content,
        ':task_type' => $taskInput['task_type'],
        ':starts_at' => $taskInput['starts_at'],
        ':scheduled_at' => $scheduledAt,
        ':sort_order' => nextTaskOrder($pdo, $userId),
    ]);

    $task = $statement->fetch();
    $pdo->commit();
    respond(['data' => $task], 201);
} catch (InvalidArgumentException $exception) {
    respond(['error' => $exception->getMessage()], 422);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    respond(['error' => 'TODOを追加できませんでした。'], 500);
}
