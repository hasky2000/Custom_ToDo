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

    $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
    $content = $input['content'] ?? null;
    $scheduledAt = $input['scheduled_at'] ?? null;

    if ($title === '') {
        respond(['error' => 'タイトルは必須です。'], 422);
    }

    if ($content !== null && !is_string($content)) {
        respond(['error' => '内容は文字列で入力してください。'], 422);
    }

    $content = is_string($content) ? trim($content) : null;
    $content = $content === '' ? null : $content;

    if ($scheduledAt !== null && !is_string($scheduledAt)) {
        respond(['error' => '予定日時は文字列で指定してください。'], 422);
    }

    $scheduledAt = is_string($scheduledAt) ? trim($scheduledAt) : null;
    $scheduledAt = $scheduledAt === '' ? null : $scheduledAt;

    if ($scheduledAt !== null) {
        try {
            $scheduledAt = (new DateTimeImmutable($scheduledAt))->format(DateTimeInterface::ATOM);
        } catch (Exception) {
            respond(['error' => '予定日時の形式が正しくありません。'], 422);
        }
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $sql = 'INSERT INTO tasks (user_id, title, content, scheduled_at)
            VALUES (:user_id, :title, :content, :scheduled_at)
            RETURNING id, title, content, completed, progress, scheduled_at, created_at, updated_at';
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':content' => $content,
        ':scheduled_at' => $scheduledAt,
    ]);

    respond(['data' => $statement->fetch()], 201);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => 'TODOを追加できませんでした。'], 500);
}
