<?php

declare(strict_types=1);

// tests/run.sh から実行する。誤って通常のDBを使わないようにする。
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
function verify(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}

function requestApi(string $endpoint, string $method, ?array $body, string &$cookie, int $expectedStatus): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nCookie: {$cookie}\r\n",
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $response = file_get_contents("http://127.0.0.1:8091/api/{$endpoint}.php", false, $context);
    $headers = $http_response_header;
    preg_match('/^HTTP\/\S+ (\d+)/', $headers[0], $match);
    verify((int) $match[1] === $expectedStatus, "{$endpoint}: expected {$expectedStatus}, got {$headers[0]} {$response}");
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) {
            $cookie = $match[1];
        }
    }
    if ($endpoint === 'debug' && $expectedStatus === 200) {
        $cacheHeaders = array_filter($headers, fn (string $header): bool => stripos($header, 'Cache-Control:') === 0);
        verify(str_contains(strtolower(implode(' ', $cacheHeaders)), 'no-store'), 'デバッグ情報をキャッシュしない');
    }
    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
}

function activeIds(string &$cookie): array
{
    return array_column(requestApi('get_tasks', 'GET', null, $cookie, 200)['data'], 'id');
}

try {
    // サーバーの起動を短時間待つ。
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8091);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }

    $cookie = '';
    requestApi('restore_task', 'PATCH', ['id' => 1], $cookie, 401);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [1]], $cookie, 401);
    requestApi('debug', 'GET', null, $cookie, 401);
    requestApi('debug', 'POST', null, $cookie, 405);
    requestApi('colors', 'GET', null, $cookie, 401);
    requestApi('colors', 'PUT', ['colors' => (object) []], $cookie, 401);
    requestApi('colors', 'POST', null, $cookie, 405);
    $registration = ['email' => 'first@example.invalid', 'password' => 'test-password'];
    requestApi('register', 'POST', $registration, $cookie, 422);
    requestApi('register', 'POST', $registration + ['password_confirmation' => 'different'], $cookie, 422);
    requestApi('register', 'POST', $registration + ['password_confirmation' => 123], $cookie, 422);
    verify((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, '不一致ではアカウントを作成しない');
    $registeredUser = requestApi('register', 'POST', $registration + ['password_confirmation' => 'test-password'], $cookie, 201)['data'];
    verify(activeIds($cookie) === [], '新規アカウントの一覧は空');
    $debug = requestApi('debug', 'GET', null, $cookie, 200)['data'];
    verify($debug['account']['id'] === $registeredUser['id'] && $debug['account']['email'] === $registration['email'], 'デバッグに自分のアカウントを表示');
    verify(password_verify($registration['password'], $debug['account']['password_hash']), '保存済みハッシュが登録パスワードと一致');
    verify(!array_key_exists('password', $debug['account']) && !array_key_exists('session_id', $debug['session']), '平文パスワードやセッションIDを返さない');
    verify($debug['session']['authentication_method'] === 'registration' && $debug['session']['password_verified'] === false, '新規登録とログイン照合を区別');
    verify($debug['task_summary']['total'] === 0 && $debug['tasks'] === [], 'タスクが0件でもデバッグ情報を取得');
    verify($debug['runtime']['has_sort_order_column'] && $debug['runtime']['has_completed_at_column'], 'DBの保存カラムを確認');
    $firstHash = $debug['account']['password_hash'];
    verify(requestApi('colors', 'GET', null, $cookie, 200)['data']['colors'] === [], '未設定では共通の配色を使う');
    $customColors = ['primary' => '#aabbcc', 'page' => '#112233', 'text' => '#5059A6'];
    $normalizedColors = array_map('strtoupper', $customColors);
    verify(requestApi('colors', 'PUT', ['colors' => $customColors], $cookie, 200)['data']['colors'] === $normalizedColors, 'コンポーネント別の配色を保存しHEXを統一');
    foreach ([
        [],
        ['colors' => null],
        ['colors' => []],
        ['colors' => ['primary' => '#fff']],
        ['colors' => ['primary' => '#123456;display:none']],
        ['colors' => ['primary' => 'url(https://example.invalid)']],
        ['colors' => ['primary' => 123456]],
        ['colors' => ['unknown' => '#123456']],
    ] as $invalidColors) {
        requestApi('colors', 'PUT', $invalidColors, $cookie, 422);
    }
    verify(requestApi('colors', 'GET', null, $cookie, 200)['data']['colors'] == $normalizedColors, '不正な配色では保存値を変更しない');

    $loginCookie = '';
    foreach ([
        ['email' => $registration['email'], 'password' => 'wrong-password'],
        ['email' => $registration['email'], 'password' => 'TEST-PASSWORD'],
        ['email' => 'unknown@example.invalid', 'password' => $registration['password']],
        ['email' => 'invalid-email', 'password' => $registration['password']],
        ['email' => $registration['email'], 'password' => ''],
        ['email' => $registration['email'], 'password' => 123],
    ] as $invalidLogin) {
        requestApi('login', 'POST', $invalidLogin, $loginCookie, 401);
    }
    requestApi('me', 'GET', null, $loginCookie, 401);
    requestApi('debug', 'GET', null, $loginCookie, 401);
    requestApi('login', 'POST', $registration, $loginCookie, 200);
    verify(requestApi('colors', 'GET', null, $loginCookie, 200)['data']['colors'] == $normalizedColors, '別セッションでも配色を保持');
    $loginDebug = requestApi('debug', 'GET', null, $loginCookie, 200)['data'];
    verify($loginDebug['session']['password_verified'] === true
        && $loginDebug['session']['authentication_method'] === 'password'
        && $loginDebug['session']['password_verified_at'] !== null, '正しいパスワードでログインした場合だけ照合成功を記録');
    requestApi('logout', 'POST', null, $loginCookie, 200);

    $taskIds = [];
    foreach (['A', 'B', 'C'] as $title) {
        $task = requestApi('add_task', 'POST', [
            'title' => $title, 'content' => '検索用の内容', 'scheduled_at' => '2026-10-06T10:00:00+09:00',
        ], $cookie, 201)['data'];
        $taskIds[] = $task['id'];
    }
    [$a, $b, $c] = $taskIds;
    verify(activeIds($cookie) === [$a, $b, $c], '追加したタスクは末尾に並ぶ');
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$b, $a, $c]], $cookie, 200);
    verify(activeIds($cookie) === [$b, $a, $c], '上へ移動');
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $b, $c]], $cookie, 200);
    verify(activeIds($cookie) === [$a, $b, $c], '下へ移動');
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$c, $b, $a]], $cookie, 200);
    verify(activeIds($cookie) === [$c, $b, $a], 'ドラッグで複数位置を移動');
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $b, $c]], $cookie, 200);
    requestApi('reorder_task', 'PATCH', ['task_ids' => []], $cookie, 422);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $a, $c]], $cookie, 422);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [(string) $a, $b, $c]], $cookie, 422);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $b]], $cookie, 409);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $b, $c, 99999]], $cookie, 409);
    verify(activeIds($cookie) === [$a, $b, $c], '不正なリクエストで順番を変えない');

    requestApi('complete_task', 'PATCH', ['id' => $b], $cookie, 200);
    verify(activeIds($cookie) === [$a, $c], '完了タスクはホームから消える');
    $archive = requestApi('get_completed_tasks', 'GET', null, $cookie, 200)['data'];
    verify(count($archive) === 1 && $archive[0]['id'] === $b && $archive[0]['completed_at'] !== null, '完了日時をアーカイブに保存');
    $pdo->exec("UPDATE tasks SET completed_at = NULL WHERE id = {$b}");
    $legacyArchive = requestApi('get_completed_tasks', 'GET', null, $cookie, 200)['data'];
    verify($legacyArchive[0]['completed_at'] === $legacyArchive[0]['updated_at'], '完了日時がない旧データもアーカイブに表示できる');
    $debug = requestApi('debug', 'GET', null, $cookie, 200)['data'];
    verify($debug['task_summary']['total'] === 3 && $debug['task_summary']['active'] === 2
        && $debug['task_summary']['archived'] === 1 && $debug['task_summary']['average_progress'] === 33.3, 'デバッグの件数と平均進捗がDBと一致');
    verify(count($debug['tasks']) === 3 && $debug['task_summary']['last_updated_at'] !== null, '保存タスクと更新日時を表示');
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $b, $c]], $cookie, 409);
    requestApi('restore_task', 'PATCH', ['id' => $a], $cookie, 404);
    requestApi('restore_task', 'PATCH', ['id' => -1], $cookie, 422);
    requestApi('restore_task', 'POST', ['id' => $b], $cookie, 405);

    $otherCookie = '';
    requestApi('register', 'POST', [
        'email' => 'second@example.invalid', 'password' => 'test-password', 'password_confirmation' => 'test-password',
    ], $otherCookie, 201);
    verify(requestApi('colors', 'GET', ['user_id' => $registeredUser['id']], $otherCookie, 200)['data']['colors'] === [], '他人の配色を取得しない');
    requestApi('colors', 'PUT', ['user_id' => $registeredUser['id'], 'colors' => ['primary' => '#123456']], $otherCookie, 200);
    verify(requestApi('colors', 'GET', null, $cookie, 200)['data']['colors'] == $normalizedColors, '他人の配色を更新しない');
    verify(requestApi('colors', 'PUT', ['colors' => (object) []], $otherCookie, 200)['data']['colors'] === [], '初期配色へのリセットを保存');
    verify(requestApi('colors', 'GET', null, $otherCookie, 200)['data']['colors'] === [], 'リセット後の再取得でも初期配色を使う');
    requestApi('restore_task', 'PATCH', ['id' => $b], $otherCookie, 404);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$c, $a]], $otherCookie, 409);
    verify(requestApi('get_completed_tasks', 'GET', null, $otherCookie, 200)['data'] === [], '他のユーザーのアーカイブを表示しない');
    verify(activeIds($cookie) === [$a, $c], '他のユーザーの操作で順番を変えない');
    $otherDebug = requestApi('debug', 'GET', ['user_id' => $registeredUser['id']], $otherCookie, 200)['data'];
    verify($otherDebug['account']['id'] !== $registeredUser['id']
        && $otherDebug['account']['password_hash'] !== $firstHash
        && $otherDebug['tasks'] === [] && $otherDebug['task_summary']['total'] === 0, '他人のアカウント・パスワードハッシュ・タスクを表示しない');

    $restored = requestApi('restore_task', 'PATCH', ['id' => $b], $cookie, 200)['data'];
    verify($restored['completed'] === false && $restored['progress'] === 0, '復元で未完了・進捗0%に戻す');
    verify($restored['title'] === 'B' && $restored['content'] === '検索用の内容' && $restored['scheduled_at'] !== null, '復元でタイトル・内容・予定日時を保持');
    verify(activeIds($cookie) === [$a, $c, $b], '復元したタスクは末尾へ');
    verify(requestApi('get_completed_tasks', 'GET', null, $cookie, 200)['data'] === [], '復元したタスクはアーカイブから消える');
    verify($pdo->query("SELECT completed_at FROM tasks WHERE id = {$b}")->fetchColumn() === null, '復元で完了日時をクリア');
    requestApi('restore_task', 'PATCH', ['id' => $b], $cookie, 404);
    requestApi('update_task', 'PATCH', ['id' => $b, 'progress' => 100], $cookie, 200);
    $archive = requestApi('get_completed_tasks', 'GET', null, $cookie, 200)['data'];
    verify($archive[0]['completed_at'] !== null && $archive[0]['progress'] === 100, '進捗100%でも完了日時を保存');
    requestApi('restore_task', 'PATCH', ['id' => $b], $cookie, 200);
    requestApi('reorder_task', 'PATCH', ['task_ids' => [$a, $b, $c]], $cookie, 200);
    requestApi('logout', 'POST', null, $cookie, 200);
    requestApi('debug', 'GET', null, $cookie, 401);
    requestApi('login', 'POST', $registration, $cookie, 200);
    verify(activeIds($cookie) === [$a, $b, $c], '再ログイン後も並び順を保持');
    verify(requestApi('debug', 'GET', null, $cookie, 200)['data']['session']['password_verified'] === true, '再ログインでもパスワードを照合');
    $statement = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $statement->execute([
        ':hash' => password_hash(getenv('POSTGRES_PASSWORD'), PASSWORD_DEFAULT),
        ':id' => $registeredUser['id'],
    ]);
    $legacyDebug = requestApi('debug', 'GET', null, $cookie, 200)['data'];
    verify($legacyDebug['password']['requires_reset'] === true
        && $legacyDebug['account']['password_hash'] === null, '旧不具合によるDB接続用パスワードのハッシュは表示せず再設定を案内');

    echo "API integration: {$checks} checks passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    fclose($serverLog);
}
