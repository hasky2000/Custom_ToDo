<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $statusCode): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond(['error' => 'GETメソッドを使用してください。'], 405);
}

require_once dirname(__DIR__, 2) . '/app/session.php';
startAppSession();
header('Cache-Control: no-store, private');
$userId = currentUserId();

if ($userId === null) {
    respond(['error' => 'ログインが必要です。'], 401);
}

try {
    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    // URLやリクエストからユーザーIDを受け取らず、自分のデータだけを返す。
    $statement = $pdo->prepare('SELECT id, email, password_hash, created_at FROM users WHERE id = :user_id');
    $statement->execute([':user_id' => $userId]);
    $account = $statement->fetch();
    if ($account === false) {
        respond(['error' => 'ログインが必要です。'], 401);
    }
    $account['id'] = (int) $account['id'];
    $passwordInfo = password_get_info($account['password_hash']);
    $requiresRehash = password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT);
    // 旧不具合ではDB接続用パスワードのハッシュが保存されていた。
    // それに一致するハッシュは、再設定が必要な情報として扱う。
    $requiresPasswordReset = password_verify($databasePassword, $account['password_hash']);
    if ($requiresPasswordReset) {
        $account['password_hash'] = null;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE completed = FALSE) AS active,
                COUNT(*) FILTER (WHERE completed = TRUE) AS archived,
                COALESCE(ROUND(AVG(progress), 1), 0) AS average_progress,
                MAX(updated_at) AS last_updated_at
         FROM tasks WHERE user_id = :user_id'
    );
    $statement->execute([':user_id' => $userId]);
    $summary = $statement->fetch();
    foreach (['total', 'active', 'archived'] as $key) {
        $summary[$key] = (int) $summary[$key];
    }
    $summary['average_progress'] = (float) $summary['average_progress'];

    $statement = $pdo->prepare(
        'SELECT id, title, content, completed, progress, task_type, starts_at, scheduled_at, sort_order,
                created_at, updated_at, completed_at
         FROM tasks WHERE user_id = :user_id ORDER BY sort_order ASC, id ASC'
    );
    $statement->execute([':user_id' => $userId]);
    $tasks = $statement->fetchAll();

    $columns = $pdo->query(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = CURRENT_SCHEMA() AND table_name = 'tasks'"
    )->fetchAll(PDO::FETCH_COLUMN);

    respond(['data' => [
        'account' => $account,
        'password' => [
            'algorithm' => $passwordInfo['algoName'],
            'parameters' => $passwordInfo['options'],
            'verification_function' => 'password_verify',
            'requires_rehash' => $requiresRehash,
            'requires_reset' => $requiresPasswordReset,
        ],
        'session' => [
            'name' => session_name(),
            'authentication_method' => $_SESSION['authentication_method'] ?? null,
            'authenticated_at' => $_SESSION['authenticated_at'] ?? null,
            'password_verified_at' => $_SESSION['password_verified_at'] ?? null,
            'password_verified' => isset($_SESSION['password_verified_at']),
            'cookie_settings' => session_get_cookie_params(),
        ],
        'task_summary' => $summary,
        'tasks' => $tasks,
        'runtime' => [
            'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'server_timezone' => date_default_timezone_get(),
            'php_version' => PHP_VERSION,
            'database_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'database_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'database_timezone' => $pdo->query('SHOW TIMEZONE')->fetchColumn(),
            'has_sort_order_column' => in_array('sort_order', $columns, true),
            'has_completed_at_column' => in_array('completed_at', $columns, true),
        ],
    ]], 200);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => 'デバッグ情報を取得できませんでした。'], 500);
}
