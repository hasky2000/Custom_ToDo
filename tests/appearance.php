<?php

declare(strict_types=1);

// 今回追加した表示設定だけを、検証専用DBで確認する。
if (!str_starts_with(getenv('POSTGRES_DB') ?: '', 'custom_todo_test_')) {
    throw new RuntimeException('検証専用DBで実行してください。');
}

$appDirectory = '/var/www/html';
$pdo = require $appDirectory . '/app/db.php';
$serverLog = tmpfile();
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8091', '-t', $appDirectory . '/public'],
    [0 => ['file', '/dev/null', 'r'], 1 => $serverLog, 2 => $serverLog],
    $pipes
);
$checks = 0;

function verifyAppearance(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}

function appearanceRequest(string $endpoint, string $method, ?array $body, string &$cookie, int $expectedStatus = 200): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nCookie: {$cookie}\r\n",
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $response = file_get_contents("http://127.0.0.1:8091/api/{$endpoint}.php", false, $context);
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0], $status);
    verifyAppearance((int) $status[1] === $expectedStatus, "{$endpoint}: {$http_response_header[0]} {$response}");
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) {
            $cookie = $match[1];
        }
    }
    return json_decode($response, true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
}

try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8091);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }

    // ログインと既存の配色はテストの準備にだけ使い、認証・タスクの全件テストは実行しない。
    $insertUser = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash) RETURNING id');
    $userIds = [];
    foreach (['appearance-first@example.invalid', 'appearance-second@example.invalid'] as $email) {
        $insertUser->execute([':email' => $email, ':hash' => password_hash('appearance-test-password', PASSWORD_DEFAULT)]);
        $userIds[] = (int) $insertUser->fetchColumn();
    }
    $colors = ['primary' => '#90A9FD'];
    $insertColors = $pdo->prepare('INSERT INTO user_colors (user_id, colors) VALUES (:id, :colors)');
    $insertColors->execute([':id' => $userIds[0], ':colors' => json_encode($colors, JSON_THROW_ON_ERROR)]);
    $cookie = '';
    appearanceRequest('colors', 'PUT', ['colors' => (object) [], 'appearance' => ['font' => 'serif']], $cookie, 401);
    appearanceRequest('login', 'POST', ['email' => 'appearance-first@example.invalid', 'password' => 'appearance-test-password'], $cookie);
    $initial = appearanceRequest('colors', 'GET', null, $cookie);
    verifyAppearance($initial['colors'] == $colors && $initial['appearance'] === [], '既存の配色を保持し、追加設定は初期状態から始める');

    $settings = [
        'input_border_width' => 0,
        'card_border_width' => 3,
        'button_border_width' => 6,
        'blur_enabled' => true,
        'shadow_enabled' => false,
        'font' => 'serif',
    ];
    $saved = appearanceRequest('colors', 'PUT', ['colors' => $colors, 'appearance' => $settings], $cookie);
    verifyAppearance($saved['appearance'] == $settings && $saved['colors'] == $colors, '枠の最小・最大値、ブラー、影、フォントを配色と一緒に保存');
    verifyAppearance(appearanceRequest('colors', 'GET', null, $cookie)['appearance'] == $settings, '再取得でも設定を保持');

    // appearance を送らない旧画面でも、表示設定を消さずに配色を更新できる。
    $updatedColors = ['primary' => '#5059A6'];
    $legacyUpdate = appearanceRequest('colors', 'PUT', ['colors' => $updatedColors], $cookie);
    verifyAppearance($legacyUpdate['appearance'] == $settings && $legacyUpdate['colors'] == $updatedColors, '配色だけの更新で表示設定を維持');

    foreach ([
        null,
        [],
        ['input_border_width' => -1],
        ['input_border_width' => 7],
        ['card_border_width' => 1.5],
        ['button_border_width' => '2'],
        ['button_border_width' => null],
        ['blur_enabled' => 'false'],
        ['blur_enabled' => 1],
        ['shadow_enabled' => null],
        ['font' => 'url(https://example.invalid)'],
        ['font' => 123],
        ['unknown' => true],
    ] as $invalid) {
        appearanceRequest('colors', 'PUT', ['colors' => ['primary' => '#FFFFFF'], 'appearance' => $invalid], $cookie, 422);
    }
    $afterInvalid = appearanceRequest('colors', 'GET', null, $cookie);
    verifyAppearance($afterInvalid['appearance'] == $settings && $afterInvalid['colors'] == $updatedColors, '不正な表示設定では配色も設定も更新しない');

    foreach (['default', 'sans', 'serif', 'rounded', 'system'] as $font) {
        $settings['font'] = $font;
        verifyAppearance(appearanceRequest('colors', 'PUT', ['colors' => $updatedColors, 'appearance' => $settings], $cookie)['appearance']['font'] === $font, "フォント {$font} を保存できる");
    }
    $settings['blur_enabled'] = false;
    $settings['shadow_enabled'] = true;
    verifyAppearance(appearanceRequest('colors', 'PUT', ['colors' => $updatedColors, 'appearance' => $settings], $cookie)['appearance'] == $settings, 'ブラー無効・影有効も正しい真偽値で保持');

    $otherCookie = '';
    appearanceRequest('login', 'POST', ['email' => 'appearance-second@example.invalid', 'password' => 'appearance-test-password'], $otherCookie);
    verifyAppearance(appearanceRequest('colors', 'GET', ['user_id' => $userIds[0]], $otherCookie)['appearance'] === [], '別アカウントに設定を引き継がない');
    appearanceRequest('colors', 'PUT', ['user_id' => $userIds[0], 'colors' => (object) [], 'appearance' => ['font' => 'rounded']], $otherCookie);
    verifyAppearance(appearanceRequest('colors', 'GET', null, $cookie)['appearance'] == $settings, '他人の表示設定を上書きしない');

    appearanceRequest('logout', 'POST', null, $cookie);
    appearanceRequest('login', 'POST', ['email' => 'appearance-first@example.invalid', 'password' => 'appearance-test-password'], $cookie);
    verifyAppearance(appearanceRequest('colors', 'GET', null, $cookie)['appearance'] == $settings, '再ログイン後も表示設定を保持');
    $reset = appearanceRequest('colors', 'PUT', ['colors' => (object) [], 'appearance' => (object) []], $cookie);
    verifyAppearance($reset['appearance'] === [] && $reset['colors'] === [], '表示設定と配色を初期状態に戻す');
    verifyAppearance(appearanceRequest('colors', 'GET', null, $cookie)['appearance'] === [], '初期設定へのリセットを保存');
    echo "Appearance integration: {$checks} checks passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    fclose($serverLog);
}
