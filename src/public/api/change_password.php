<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

// 別サイトのフォームからの送信を受け付けず、JSONのリクエストに限定する。
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    respond(['error' => 'Content-Typeにapplication/jsonを指定してください。'], 415);
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        respond(['error' => 'JSONオブジェクトを送信してください。'], 400);
    }

    $currentPassword = $input['current_password'] ?? null;
    $newPassword = $input['new_password'] ?? null;
    $newPasswordConfirmation = $input['new_password_confirmation'] ?? null;
    if (!is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > 4096
        || str_contains($currentPassword, "\0")) {
        respond(['error' => '現在のパスワードを入力してください。'], 422);
    }

    // PASSWORD_DEFAULTで使うbcryptでは、72バイトを超える部分が検証されないため拒否する。
    if (!is_string($newPassword) || preg_match('/\A.{8,}\z/us', $newPassword) !== 1) {
        respond(['error' => 'パスワードは8文字以上で入力してください。'], 422);
    }
    if (strlen($newPassword) > 72) {
        respond(['error' => 'パスワードは72バイト以内で入力してください。'], 422);
    }
    if (str_contains($newPassword, "\0")) {
        respond(['error' => 'パスワードに使用できない文字が含まれています。'], 422);
    }
    if (!is_string($newPasswordConfirmation) || $newPassword !== $newPasswordConfirmation) {
        respond(['error' => '確認用パスワードが一致しません。'], 422);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $statement->execute([':id' => $userId]);
    $currentHash = $statement->fetchColumn();

    if ($currentHash === false) {
        respond(['error' => 'ログインが必要です。'], 401);
    }
    if (!password_verify($currentPassword, $currentHash)) {
        respond(['error' => '現在のパスワードが正しくありません。'], 422);
    }
    if (password_verify($newPassword, $currentHash)) {
        respond(['error' => '新しいパスワードは現在のパスワードと異なるものを入力してください。'], 422);
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    // 別セッションで先に変更されていた場合は、古いパスワードで上書きしない。
    $statement = $pdo->prepare(
        'UPDATE users SET password_hash = :new_hash WHERE id = :id AND password_hash = :current_hash'
    );
    $statement->execute([':new_hash' => $newHash, ':id' => $userId, ':current_hash' => $currentHash]);
    if ($statement->rowCount() !== 1) {
        respond(['error' => 'パスワードが変更されています。現在のパスワードを確認して再度お試しください。'], 409);
    }

    session_regenerate_id(true);
    respond(['data' => ['password_changed' => true]], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => 'パスワードを変更できませんでした。'], 500);
}
