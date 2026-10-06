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
    $taskIds = is_array($input) ? ($input['task_ids'] ?? null) : null;

    if (!is_array($taskIds) || !array_is_list($taskIds) || $taskIds === []) {
        respond(['error' => 'TODO IDの配列を指定してください。'], 422);
    }
    foreach ($taskIds as $taskId) {
        if (!is_int($taskId) || $taskId <= 0) {
            respond(['error' => '正しいTODO IDを指定してください。'], 422);
        }
    }
    if (count(array_unique($taskIds)) !== count($taskIds)) {
        respond(['error' => 'TODO IDは重複させないでください。'], 422);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $pdo->beginTransaction();
    lockTaskOrder($pdo, $userId);
    $statement = $pdo->prepare(
        'SELECT id FROM tasks
         WHERE user_id = :user_id AND completed = FALSE
         ORDER BY sort_order ASC, id ASC FOR UPDATE'
    );
    $statement->execute([':user_id' => $userId]);
    $currentIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $requestedIds = $taskIds;
    sort($currentIds);
    sort($requestedIds);

    // 全件が現在のユーザーの未完了タスクと一致するときだけ順番を保存する。
    // 別の画面で追加・完了された場合や他人のIDを含む場合は一切更新しない。
    if ($requestedIds !== $currentIds) {
        $pdo->rollBack();
        respond(['error' => 'タスクが変更されています。一覧を読み直して並べ替えてください。'], 409);
    }

    $statement = $pdo->prepare(
        'UPDATE tasks SET sort_order = :sort_order
         WHERE id = :id AND user_id = :user_id AND completed = FALSE'
    );
    foreach ($taskIds as $index => $taskId) {
        $statement->execute([
            ':sort_order' => $index + 1,
            ':id' => $taskId,
            ':user_id' => $userId,
        ]);
    }
    $pdo->commit();

    respond(['data' => ['task_ids' => $taskIds]], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    respond(['error' => 'TODOを並べ替えできませんでした。'], 500);
}
