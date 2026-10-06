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

try {
    $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    $email = is_array($input) && is_string($input['email'] ?? null)
        ? strtolower(trim($input['email']))
        : '';
    $password = is_array($input) && is_string($input['password'] ?? null)
        ? $input['password']
        : '';

    if ($email === '' || $password === '' || strlen($password) > 4096) {
        respond(['error' => 'メールアドレスまたはパスワードが正しくありません。'], 401);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $statement = $pdo->prepare(
        'SELECT id, email, password_hash FROM users WHERE email = :email'
    );
    $statement->execute([':email' => $email]);
    $user = $statement->fetch();

    if ($user === false || !password_verify($password, $user['password_hash'])) {
        respond(['error' => 'メールアドレスまたはパスワードが正しくありません。'], 401);
    }

    require_once dirname(__DIR__, 2) . '/app/session.php';
    startAppSession();
    session_regenerate_id();
    $_SESSION['user_id'] = (int) $user['id'];

    respond(['data' => ['id' => (int) $user['id'], 'email' => $user['email']]], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => 'ログインできませんでした。'], 500);
}
