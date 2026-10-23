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

function requestApi(string $endpoint, string $method, ?array $body, string &$cookie, int $expectedStatus, bool $expectJson = true): array
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
    return $expectJson ? json_decode($response, true, 512, JSON_THROW_ON_ERROR) : [];
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
    requestApi('debug', 'GET', null, $cookie, 404, false);
    requestApi('debug', 'POST', null, $cookie, 404, false);
    $index = file_get_contents('http://127.0.0.1:8091/');
    verify(!str_contains($index, 'debug-page') && !str_contains($index, 'href="#debug"'), 'デバッグ画面とリンクを公開しない');
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
    requestApi('debug', 'GET', null, $cookie, 404, false);
    $account = requestApi('me', 'GET', null, $cookie, 200)['data'];
    verify((int) $account['id'] === $registeredUser['id'] && $account['email'] === $registration['email'], 'ログイン中の自分のアカウントを取得');
    verify(array_diff(array_keys($account), ['id', 'email']) === [], 'アカウントAPIはIDとメールアドレスだけを返す');
    verify(array_diff(array_keys($registeredUser), ['id', 'email']) === [], '登録APIはIDとメールアドレスだけを返す');
    // パスワードの保存状態は公開APIを経由せず、検証用DBを直接確認する。
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $statement->execute([':id' => $registeredUser['id']]);
    $firstHash = $statement->fetchColumn();
    verify(password_verify($registration['password'], $firstHash), '登録パスワードをハッシュ化して保存');
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

    $textBackgrounds = [
        'text' => ['page', 'surface', 'header', 'card', 'input', 'secondary', 'navigation', 'navigation_active'],
        'primary_text' => ['primary'],
        'secondary_text' => ['secondary'],
    ];
    foreach ($textBackgrounds as $textKey => $backgrounds) {
        foreach ($backgrounds as $background) {
            $error = requestApi('colors', 'PUT', ['colors' => [$textKey => '#aabbcc', $background => '#AABBCC']], $cookie, 422);
            verify(str_contains($error['error'], '文字色と背景色'), '文字が消える配色には理由を返す');
        }
    }
    foreach ([['text' => '#ffffff'], ['primary' => '#5059a6'], ['primary_text' => '#90a9fd'], ['secondary_text' => '#eef0ff']] as $invisibleColors) {
        requestApi('colors', 'PUT', ['colors' => $invisibleColors], $cookie, 422);
    }
    verify(requestApi('colors', 'GET', null, $cookie, 200)['data']['colors'] == $normalizedColors, '同色化を拒否した後も保存済み配色を保持する');
    foreach ($textBackgrounds as $textKey => $backgrounds) {
        foreach ($backgrounds as $background) {
            foreach ([['#112233', '#122637'], ['#122637', '#112233']] as [$textColor, $backgroundColor]) {
                $error = requestApi('colors', 'PUT', ['colors' => [$textKey => $textColor, $background => $backgroundColor]], $cookie, 422);
                verify(str_contains($error['error'], '10以上'), 'RGB差の絶対値合計が9なら保存しない');
            }
            foreach ([['#112233', '#122638'], ['#122638', '#112233']] as [$textColor, $backgroundColor]) {
                $readableColors = [$textKey => $textColor, $background => $backgroundColor];
                verify(requestApi('colors', 'PUT', ['colors' => $readableColors], $cookie, 200)['data']['colors'] == $readableColors, 'RGB差の絶対値合計が10なら保存できる');
            }
        }
    }
    $sameBackgrounds = array_fill_keys(['page', 'surface', 'header', 'card', 'input', 'primary', 'secondary', 'border', 'progress', 'navigation', 'navigation_active'], '#222222');
    verify(requestApi('colors', 'PUT', ['colors' => $sameBackgrounds], $cookie, 200)['data']['colors'] == $sameBackgrounds, '文字が異なる色なら背景同士は統一できる');
    requestApi('colors', 'PUT', ['colors' => $customColors], $cookie, 200);

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
    $loggedInUser = requestApi('login', 'POST', $registration, $loginCookie, 200)['data'];
    verify(array_diff(array_keys($loggedInUser), ['id', 'email']) === [], 'ログインAPIはIDとメールアドレスだけを返す');
    verify((int) requestApi('me', 'GET', null, $loginCookie, 200)['data']['id'] === $registeredUser['id'], '正しいパスワードでログイン');
    verify(requestApi('colors', 'GET', null, $loginCookie, 200)['data']['colors'] == $normalizedColors, '別セッションでも配色を保持');
    requestApi('logout', 'POST', null, $loginCookie, 200);

    foreach (['', ' ', '　', " \t　\n", "\u{00A0}\u{2003}\u{FEFF}"] as $emptyTitle) {
        $error = requestApi('add_task', 'POST', ['title' => $emptyTitle], $cookie, 422);
        verify(str_contains($error['error'], 'タイトルを入力'), '空白だけのタイトルには入力案内を返す');
    }
    $pastSchedule = (new DateTimeImmutable('-1 minute', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    foreach ([$pastSchedule, 'not-a-date', '2099-02-30T12:00:00+09:00', 123] as $invalidSchedule) {
        $error = requestApi('add_task', 'POST', ['title' => '保存されないTODO', 'scheduled_at' => $invalidSchedule], $cookie, 422);
        verify(str_contains($error['error'], '締切日時'), '締切日時のエラーを返す');
    }
    verify(activeIds($cookie) === [], '不正な入力ではタスクを作成しない');

    $futureSchedule = (new DateTimeImmutable('+1 day', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    $futureEnd = (new DateTimeImmutable('+2 days', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
    $schedules = [
        ['task_type' => 'unscheduled', 'starts_at' => null, 'scheduled_at' => null],
        ['task_type' => 'deadline', 'starts_at' => null, 'scheduled_at' => $futureEnd],
        ['task_type' => 'period', 'starts_at' => $futureSchedule, 'scheduled_at' => $futureEnd],
    ];
    $scheduleTaskIds = [];
    foreach ($schedules as $schedule) {
        $created = requestApi('add_task', 'POST', ['title' => '種類の検証'] + $schedule, $cookie, 201)['data'];
        $scheduleTaskIds[] = $created['id'];
        verify($created['task_type'] === $schedule['task_type']
            && ($created['starts_at'] === null) === ($schedule['starts_at'] === null)
            && ($created['scheduled_at'] === null) === ($schedule['scheduled_at'] === null), '3種類の日時の有無を正しく保存');
        requestApi('complete_task', 'PATCH', ['id' => $created['id']], $cookie, 200);
        $archived = requestApi('get_completed_tasks', 'GET', null, $cookie, 200)['data'][0];
        verify($archived['task_type'] === $created['task_type'] && $archived['starts_at'] === $created['starts_at']
            && $archived['scheduled_at'] === $created['scheduled_at'], '完了後も種類・開始・締切を保持');
        $restored = requestApi('restore_task', 'PATCH', ['id' => $created['id']], $cookie, 200)['data'];
        verify($restored['task_type'] === $created['task_type'] && $restored['starts_at'] === $created['starts_at'], '復元でも日時を保持');
    }
    $scheduleTaskId = $scheduleTaskIds[0];
    foreach ($schedules as $schedule) {
        $edited = requestApi('edit_task', 'PATCH', ['id' => $scheduleTaskId, 'title' => '種類を変更'] + $schedule, $cookie, 200)['data'];
        verify($edited['task_type'] === $schedule['task_type'], '編集でタスクの種類を切り替えられる');
    }
    $invalidSchedules = [
        ['task_type' => 'unknown'], ['task_type' => null], ['task_type' => []],
        ['task_type' => 'unscheduled', 'scheduled_at' => $futureEnd],
        ['task_type' => 'unscheduled', 'starts_at' => $futureSchedule],
        ['task_type' => 'deadline'],
        ['task_type' => 'deadline', 'starts_at' => $futureSchedule, 'scheduled_at' => $futureEnd],
        ['task_type' => 'period', 'starts_at' => $futureSchedule],
        ['task_type' => 'period', 'scheduled_at' => $futureEnd],
        ['task_type' => 'period', 'starts_at' => $futureSchedule, 'scheduled_at' => $futureSchedule],
        ['task_type' => 'period', 'starts_at' => $futureEnd, 'scheduled_at' => $futureSchedule],
        ['task_type' => 'period', 'starts_at' => $pastSchedule, 'scheduled_at' => $futureEnd],
        ['task_type' => 'period', 'starts_at' => 'not-a-date', 'scheduled_at' => $futureEnd],
        ['task_type' => 'period', 'starts_at' => 123, 'scheduled_at' => $futureEnd],
    ];
    foreach ($invalidSchedules as $schedule) {
        requestApi('add_task', 'POST', ['title' => '不正な種類'] + $schedule, $cookie, 422);
        requestApi('edit_task', 'PATCH', ['id' => $scheduleTaskId, 'title' => '不正な編集'] + $schedule, $cookie, 422);
    }
    verify(count(activeIds($cookie)) === 3, '不正な種類・日時では追加しない');
    $unchangedPeriod = requestApi('get_tasks', 'GET', null, $cookie, 200)['data'][0];
    verify($unchangedPeriod['task_type'] === 'period' && $unchangedPeriod['title'] === '種類を変更', '不正な編集で種類やタイトルを変更しない');

    // 時間経過後の状態をDBで再現し、元の日時のまま編集できることを確認する。
    $expiredStart = (new DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i:s.uP');
    $expiredEnd = (new DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i:s.uP');
    $statement = $pdo->prepare('UPDATE tasks SET starts_at = :start, scheduled_at = :end WHERE id = :id');
    $statement->execute([':start' => $expiredStart, ':end' => $expiredEnd, ':id' => $scheduleTaskId]);
    $expiredPayload = ['id' => $scheduleTaskId, 'title' => '期限超過後の編集', 'task_type' => 'period', 'starts_at' => $expiredStart, 'scheduled_at' => $expiredEnd];
    verify(requestApi('edit_task', 'PATCH', $expiredPayload, $cookie, 200)['data']['title'] === $expiredPayload['title'], '期限超過後も日時を変えず編集できる');
    $preserved = requestApi('get_tasks', 'GET', null, $cookie, 200)['data'][0];
    verify(new DateTimeImmutable($preserved['starts_at']) == new DateTimeImmutable($expiredStart)
        && new DateTimeImmutable($preserved['scheduled_at']) == new DateTimeImmutable($expiredEnd), '変更していない日時は小数秒も保持する');
    $expiredPayload['scheduled_at'] = $pastSchedule;
    requestApi('edit_task', 'PATCH', $expiredPayload, $cookie, 422);
    $unchangedPeriod = requestApi('get_tasks', 'GET', null, $cookie, 200)['data'][0];
    verify(strtotime($unchangedPeriod['scheduled_at']) === strtotime($expiredEnd), '別の過去日時への変更は拒否する');
    foreach ($scheduleTaskIds as $id) requestApi('delete_task', 'DELETE', ['id' => $id], $cookie, 200);

    $taskIds = [];
    foreach (['A', 'B', 'C'] as $title) {
        $task = requestApi('add_task', 'POST', [
            'title' => "　{$title}　", 'content' => '検索用の内容', 'scheduled_at' => $futureSchedule,
        ], $cookie, 201)['data'];
        verify($task['title'] === $title, 'タイトル前後の全角スペースを除去する');
        verify($task['task_type'] === 'deadline', '旧形式の予定日時は期限のみとして保存する');
        $taskIds[] = $task['id'];
    }
    [$a, $b, $c] = $taskIds;
    foreach (['', '　', " \t　\n"] as $emptyTitle) {
        requestApi('edit_task', 'PATCH', ['id' => $a, 'title' => $emptyTitle], $cookie, 422);
    }
    requestApi('edit_task', 'PATCH', ['id' => $a, 'title' => '変更されないタイトル', 'scheduled_at' => $pastSchedule], $cookie, 422);
    $unchangedTask = requestApi('get_tasks', 'GET', null, $cookie, 200)['data'][0];
    verify($unchangedTask['title'] === 'A' && $unchangedTask['content'] === '検索用の内容'
        && strtotime($unchangedTask['scheduled_at']) === strtotime($futureSchedule), '不正な編集では既存データを変更しない');
    $editedTask = requestApi('edit_task', 'PATCH', ['id' => $a, 'title' => '　A　', 'content' => '　', 'scheduled_at' => ''], $cookie, 200)['data'];
    verify($editedTask['title'] === 'A' && $editedTask['content'] === null && $editedTask['scheduled_at'] === null, '内容と予定日時は未指定でも保存できる');
    $utcSchedule = (new DateTimeImmutable($futureSchedule))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    $editedTask = requestApi('edit_task', 'PATCH', ['id' => $a, 'title' => 'A', 'scheduled_at' => $utcSchedule], $cookie, 200)['data'];
    verify(strtotime($editedTask['scheduled_at']) === strtotime($futureSchedule), 'ブラウザーから送るUTC形式でも同じ予定日時を保存する');
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
    $otherAccount = requestApi('me', 'GET', ['user_id' => $registeredUser['id']], $otherCookie, 200)['data'];
    verify((int) $otherAccount['id'] !== $registeredUser['id']
        && $otherAccount['email'] === 'second@example.invalid', '指定したユーザーIDではなく自分のアカウントだけを返す');

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
    requestApi('me', 'GET', null, $cookie, 401);
    requestApi('login', 'POST', $registration, $cookie, 200);
    verify(activeIds($cookie) === [$a, $b, $c], '再ログイン後も並び順を保持');

    echo "API integration: {$checks} checks passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    fclose($serverLog);
}
