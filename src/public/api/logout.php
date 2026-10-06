<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POSTメソッドを使用してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/session.php';
startAppSession();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
echo json_encode(['data' => ['logged_out' => true]], JSON_UNESCAPED_UNICODE);
