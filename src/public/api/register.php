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

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        respond(['error' => '正しいメールアドレスを入力してください。'], 422);
    }

    if (strlen($password) < 8 || strlen($password) > 4096) {
        respond(['error' => 'パスワードは8文字以上で入力してください。'], 422);
    }

    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO users (email, password_hash)
         VALUES (:email, :password_hash)
         RETURNING id, email'
    );
    $statement->execute([
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $user = $statement->fetch();

    $claimStatement = $pdo->prepare(
        'UPDATE tasks SET user_id = :user_id WHERE user_id IS NULL'
    );
    $claimStatement->execute([':user_id' => $user['id']]);
    $pdo->commit();

    require_once dirname(__DIR__, 2) . '/app/session.php';
    startAppSession();
    session_regenerate_id();
    $_SESSION['user_id'] = (int) $user['id'];

    respond(['data' => ['id' => (int) $user['id'], 'email' => $user['email']]], 201);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception->getCode() === '23505') {
        respond(['error' => 'このメールアドレスはすでに登録されています。'], 409);
    }

    error_log($exception->getMessage());
    respond(['error' => 'アカウントを作成できませんでした。'], 500);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    respond(['error' => 'アカウントを作成できませんでした。'], 500);
}
