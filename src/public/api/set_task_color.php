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
    $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        respond(['error' => 'JSONオブジェクトを送信してください。'], 400);
    }
    $id = $input['id'] ?? null;
    if (!is_int($id) || $id <= 0) {
        respond(['error' => '正しいTODO IDを指定してください。'], 422);
    }
    if (!array_key_exists('background_color', $input)) {
        respond(['error' => '色を指定してください。'], 422);
    }

    require_once dirname(__DIR__, 2) . '/app/task_colors.php';
    $color = $input['background_color'];
    if ($color !== null) {
        if (!is_string($color) || !in_array(strtoupper($color), array_column(taskColorPalette(), 'value'), true)) {
            respond(['error' => '色は16色のパレットから選択してください。'], 422);
        }
        $color = strtoupper($color);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare(
        'UPDATE tasks
         SET background_color = :background_color, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND user_id = :user_id AND completed = FALSE
         RETURNING id, background_color'
    );
    $statement->execute([':id' => $id, ':user_id' => $userId, ':background_color' => $color]);
    $task = $statement->fetch();
    if ($task === false) {
        respond(['error' => '色を変更できるTODOが見つかりません。'], 404);
    }
    respond(['data' => $task], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => '色を保存できませんでした。'], 500);
}
