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

function requestApi(string $endpoint, string $method, ?array $body, string &$cookie, int $expectedStatus, bool $expectJson = true, ?string $rawBody = null, string $contentType = 'application/json'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: {$contentType}\r\nCookie: {$cookie}\r\n",
        'content' => $rawBody ?? ($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)),
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
    requestApi('change_password', 'POST', ['current_password' => 'test-password', 'new_password' => 'new-password'], $cookie, 401);
    requestApi('change_password', 'GET', null, $cookie, 405);
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

    foreach (['a', 'あ', '😀'] as $character) {
        $limitContent = str_repeat($character, 511);
        $limitTask = requestApi('add_task', 'POST', ['title' => '内容の文字数制限', 'content' => $limitContent], $cookie, 201)['data'];
        verify($limitTask['content'] === $limitContent, '内容は日本語・絵文字も511文字まで保存できる');
        $overLimitContent = $limitContent . $character;
        $error = requestApi('add_task', 'POST', ['title' => '保存されない内容', 'content' => $overLimitContent], $cookie, 422);
        verify($error['error'] === '内容は511文字以内で入力してください。', '追加時に内容の上限超過を案内する');
        requestApi('edit_task', 'PATCH', ['id' => $limitTask['id'], 'title' => '内容の文字数制限', 'content' => $overLimitContent], $cookie, 422);
        $storedTask = requestApi('get_tasks', 'GET', null, $cookie, 200)['data'][0];
        verify($storedTask['content'] === $limitContent, '上限超過の編集では元の内容を変更しない');
        $editedLimitTask = requestApi('edit_task', 'PATCH', ['id' => $limitTask['id'], 'title' => '内容の文字数制限', 'content' => $limitContent], $cookie, 200)['data'];
        verify($editedLimitTask['content'] === $limitContent, '編集でも511文字の内容を保存できる');
        requestApi('delete_task', 'DELETE', ['id' => $limitTask['id']], $cookie, 200);
    }

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

    // パスワード変更は失敗時の保持、所有者の分離、再ログインまで検証する。
    requestApi('change_password', 'PATCH', null, $cookie, 405);
    requestApi('change_password', 'POST', null, $cookie, 400, rawBody: '{');
    requestApi('change_password', 'POST', null, $cookie, 400, rawBody: 'null');
    requestApi('change_password', 'POST', null, $cookie, 415, rawBody: '{}', contentType: 'text/plain');
    foreach ([
        [],
        ['current_password' => 'test-password'],
        ['new_password' => 'new-password'],
        ['current_password' => 'wrong-password', 'new_password' => 'new-password'],
        ['current_password' => 'TEST-PASSWORD', 'new_password' => 'new-password'],
        ['current_password' => '', 'new_password' => 'new-password'],
        ['current_password' => 123, 'new_password' => 'new-password'],
        ['current_password' => [], 'new_password' => 'new-password'],
        ['current_password' => str_repeat('a', 4097), 'new_password' => 'new-password'],
        ['current_password' => "test-password\0", 'new_password' => 'new-password'],
        ['current_password' => 'test-password', 'new_password' => null],
        ['current_password' => 'test-password', 'new_password' => 12345678],
        ['current_password' => 'test-password', 'new_password' => []],
        ['current_password' => 'test-password', 'new_password' => ''],
        ['current_password' => 'test-password', 'new_password' => '1234567'],
        ['current_password' => 'test-password', 'new_password' => str_repeat('あ', 7)],
        ['current_password' => 'test-password', 'new_password' => str_repeat('あ', 25)],
        ['current_password' => 'test-password', 'new_password' => str_repeat('a', 73)],
        ['current_password' => 'test-password', 'new_password' => "new-password\0"],
    ] as $invalidPasswordChange) {
        $invalidPasswordChange['new_password_confirmation'] = $invalidPasswordChange['new_password'] ?? null;
        requestApi('change_password', 'POST', $invalidPasswordChange, $cookie, 422);
        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute([':id' => $registeredUser['id']]);
        verify($statement->fetchColumn() === $firstHash, '不正なパスワード変更ではハッシュを変更しない');
    }

    foreach ([[], ['new_password_confirmation' => null], ['new_password_confirmation' => 123],
        ['new_password_confirmation' => []], ['new_password_confirmation' => ''],
        ['new_password_confirmation' => 'different-password'], ['new_password_confirmation' => 'NEW-PASSWORD'],
    ] as $confirmationInput) {
        $error = requestApi('change_password', 'POST', [
            'current_password' => 'test-password', 'new_password' => 'new-password',
        ] + $confirmationInput, $cookie, 422);
        verify($error['error'] === '確認用パスワードが一致しません。', '確認欄のエラー文言をアカウント作成と統一');
        $statement->execute([':id' => $registeredUser['id']]);
        verify($statement->fetchColumn() === $firstHash, '確認欄が不正な場合はハッシュを変更しない');
    }

    $samePasswordError = requestApi('change_password', 'POST', [
        'current_password' => $registration['password'],
        'new_password' => $registration['password'], 'new_password_confirmation' => $registration['password'],
    ], $cookie, 422);
    verify($samePasswordError['error'] === '新しいパスワードは現在のパスワードと異なるものを入力してください。', '現在と同じパスワードへの変更を拒否');
    $statement->execute([':id' => $registeredUser['id']]);
    verify($statement->fetchColumn() === $firstHash, '同じパスワードへの変更ではハッシュを更新しない');

    requestApi('change_password', 'POST', [
        'user_id' => $registeredUser['id'], 'current_password' => 'test-password',
        'new_password' => 'other-new-password', 'new_password_confirmation' => 'other-new-password',
    ], $otherCookie, 200);
    $statement->execute([':id' => $registeredUser['id']]);
    verify($statement->fetchColumn() === $firstHash, '他人のIDを指定しても、その人のハッシュを更新しない');
    requestApi('logout', 'POST', null, $otherCookie, 200);
    requestApi('login', 'POST', ['email' => $otherAccount['email'], 'password' => 'test-password'], $otherCookie, 401);
    requestApi('login', 'POST', ['email' => $otherAccount['email'], 'password' => 'other-new-password'], $otherCookie, 200);

    $newPassword = str_repeat('x', 72);
    $changed = requestApi('change_password', 'POST', [
        'current_password' => $registration['password'], 'new_password' => $newPassword, 'new_password_confirmation' => $newPassword,
    ], $cookie, 200);
    verify($changed === ['data' => ['password_changed' => true]], '変更APIはパスワードやハッシュを返さない');
    $statement->execute([':id' => $registeredUser['id']]);
    $changedHash = $statement->fetchColumn();
    verify($changedHash !== $firstHash && $changedHash !== $newPassword && password_verify($newPassword, $changedHash), '新しいパスワードをハッシュ化して保存');
    verify(!password_verify($registration['password'], $changedHash), '更新後のハッシュは旧パスワードに一致しない');
    verify((int) requestApi('me', 'GET', null, $cookie, 200)['data']['id'] === $registeredUser['id'], '変更後もログイン状態を維持');
    requestApi('change_password', 'POST', [
        'current_password' => $registration['password'], 'new_password' => 'stale-password', 'new_password_confirmation' => 'stale-password',
    ], $cookie, 422);
    $statement->execute([':id' => $registeredUser['id']]);
    verify($statement->fetchColumn() === $changedHash, '変更前のパスワードでは再変更できない');
    requestApi('change_password', 'POST', [
        'current_password' => $newPassword, 'new_password' => $newPassword, 'new_password_confirmation' => $newPassword,
    ], $cookie, 422);
    $statement->execute([':id' => $registeredUser['id']]);
    verify($statement->fetchColumn() === $changedHash, '変更後も同じパスワードへの再変更を拒否し、ハッシュを保持');
    requestApi('logout', 'POST', null, $cookie, 200);
    requestApi('login', 'POST', $registration, $cookie, 401);
    requestApi('login', 'POST', ['email' => $registration['email'], 'password' => $newPassword], $cookie, 200);
    verify(activeIds($cookie) === [$a, $b, $c], 'パスワード変更後もTODOを保持');
    verify(requestApi('colors', 'GET', null, $cookie, 200)['data']['colors'] == $normalizedColors, 'パスワード変更後も配色を保持');

    $unicodePassword = str_repeat('あ', 24);
    requestApi('change_password', 'POST', ['current_password' => $newPassword, 'new_password' => $unicodePassword, 'new_password_confirmation' => $unicodePassword], $cookie, 200);
    requestApi('logout', 'POST', null, $cookie, 200);
    requestApi('login', 'POST', ['email' => $registration['email'], 'password' => $unicodePassword], $cookie, 200);
    requestApi('change_password', 'POST', ['current_password' => $unicodePassword, 'new_password' => '12345678', 'new_password_confirmation' => '12345678'], $cookie, 200);
    requestApi('logout', 'POST', null, $cookie, 200);
    requestApi('login', 'POST', ['email' => $registration['email'], 'password' => '12345678'], $cookie, 200);

    $deletedCookie = '';
    $deletedUser = requestApi('register', 'POST', [
        'email' => 'deleted@example.invalid', 'password' => 'test-password', 'password_confirmation' => 'test-password',
    ], $deletedCookie, 201)['data'];
    $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $statement->execute([':id' => $deletedUser['id']]);
    requestApi('change_password', 'POST', ['current_password' => 'test-password', 'new_password' => 'new-password', 'new_password_confirmation' => 'new-password'], $deletedCookie, 401);

    // タスク固有の背景色は、全体テーマや他人のタスクから独立して保存する。
    require_once $appDirectory . '/app/task_colors.php';
    $taskPalette = taskColorPalette();
    verify(count($taskPalette) === 16 && count(array_unique(array_column($taskPalette, 'value'))) === 16, 'タスク背景色は重複しない16色');
    $anonymousCookie = '';
    requestApi('set_task_color', 'PATCH', ['id' => $a, 'background_color' => null], $anonymousCookie, 401);
    requestApi('set_task_color', 'GET', null, $anonymousCookie, 405);
    $colorTask = requestApi('add_task', 'POST', ['title' => '背景色の検証'], $cookie, 201)['data'];
    $colorId = $colorTask['id'];
    verify($colorTask['background_color'] === null, '新規TODOは共通の背景色を使う');
    $orderBeforeColor = activeIds($cookie);
    foreach ($taskPalette as $color) {
        $saved = requestApi('set_task_color', 'PATCH', [
            'id' => $colorId, 'background_color' => strtolower($color['value']),
        ], $cookie, 200)['data'];
        verify($saved['id'] === $colorId && $saved['background_color'] === $color['value'], '16色を保存しHEXを大文字に統一');
        $backgrounds = array_column(requestApi('get_tasks', 'GET', null, $cookie, 200)['data'], 'background_color', 'id');
        verify($backgrounds[$colorId] === $color['value'], '一覧の再取得でタスク背景色を保持');
        verify($backgrounds[$a] === null, '他のタスクの背景色を変更しない');
    }
    verify(activeIds($cookie) === $orderBeforeColor, '色変更では並び順を変更しない');
    foreach (['', '#fff', '#123456', 'red', '#FECACA;display:none', 'url(https://example.invalid)', 123, [], true] as $invalidColor) {
        requestApi('set_task_color', 'PATCH', ['id' => $colorId, 'background_color' => $invalidColor], $cookie, 422);
    }
    requestApi('set_task_color', 'PATCH', ['id' => $colorId], $cookie, 422);
    foreach ([0, -1, '1', null] as $invalidId) {
        requestApi('set_task_color', 'PATCH', ['id' => $invalidId, 'background_color' => null], $cookie, 422);
    }
    requestApi('set_task_color', 'PATCH', null, $cookie, 400, true, '{');
    requestApi('set_task_color', 'PATCH', null, $cookie, 400, true, '"invalid"');
    requestApi('set_task_color', 'PATCH', ['id' => $colorId, 'background_color' => null, 'user_id' => $registeredUser['id']], $otherCookie, 404);
    $backgrounds = array_column(requestApi('get_tasks', 'GET', null, $cookie, 200)['data'], 'background_color', 'id');
    $lastColor = $taskPalette[count($taskPalette) - 1]['value'];
    verify($backgrounds[$colorId] === $lastColor, '不正値や他人の操作では保存済み色を変更しない');
    $editedColorTask = requestApi('edit_task', 'PATCH', ['id' => $colorId, 'title' => '背景色の編集'], $cookie, 200)['data'];
    verify($editedColorTask['background_color'] === $lastColor, '通常の編集で背景色を保持');
    verify(requestApi('update_task', 'PATCH', ['id' => $colorId, 'progress' => 37], $cookie, 200)['data']['background_color'] === $lastColor, '進捗の更新で背景色を保持');
    verify(requestApi('complete_task', 'PATCH', ['id' => $colorId], $cookie, 200)['data']['background_color'] === $lastColor, '完了時も背景色を保持');
    $archiveColors = array_column(requestApi('get_completed_tasks', 'GET', null, $cookie, 200)['data'], 'background_color', 'id');
    verify($archiveColors[$colorId] === $lastColor, 'アーカイブでも背景色を取得');
    requestApi('set_task_color', 'PATCH', ['id' => $colorId, 'background_color' => null], $cookie, 404);
    verify(requestApi('restore_task', 'PATCH', ['id' => $colorId], $cookie, 200)['data']['background_color'] === $lastColor, '復元でも背景色を保持');
    verify(requestApi('set_task_color', 'PATCH', ['id' => $colorId, 'background_color' => null], $cookie, 200)['data']['background_color'] === null, '標準の背景色に戻せる');
    $backgrounds = array_column(requestApi('get_tasks', 'GET', null, $cookie, 200)['data'], 'background_color', 'id');
    verify($backgrounds[$colorId] === null, '標準に戻した状態も再取得で保持');
    requestApi('delete_task', 'DELETE', ['id' => $colorId], $cookie, 200);

    // 完了・進捗100%のどちらからも次回分を作り、履歴の再完了では重複させない。
    foreach (['daily' => null, 'weekly' => 3, 'monthly' => 31] as $repeatType => $repeatDay) {
        $repeat = ['repeat_type' => $repeatType, 'repeat_day' => $repeatDay, 'repeat_time' => '09:15', 'repeat_timezone' => 'Asia/Tokyo'];
        $parent = requestApi('add_task', 'POST', ['title' => "繰り返し {$repeatType}", 'content' => '引き継ぐ内容'] + $repeat, $cookie, 201)['data'];
        verify($parent['repeat_type'] === $repeatType && $parent['repeat_day'] === $repeatDay && $parent['repeat_time'] === '09:15:00', '繰り返し設定を保存する');
        requestApi('edit_task', 'PATCH', ['id' => $parent['id'], 'title' => '他人の変更'] + $repeat, $otherCookie, 404);
        requestApi('complete_task', 'PATCH', ['id' => $parent['id']], $otherCookie, 404);
        requestApi('set_task_color', 'PATCH', ['id' => $parent['id'], 'background_color' => '#FECACA'], $cookie, 200);
        $endpoint = $repeatType === 'weekly' ? 'update_task' : 'complete_task';
        $result = requestApi($endpoint, 'PATCH', ['id' => $parent['id'], 'progress' => 100], $cookie, 200);
        $next = $result['next_task'];
        verify(is_array($next) && $next['id'] !== $parent['id'] && $result['data']['completed'], '元のタスクを完了し次回分を別IDで作る');
        verify(!$next['completed'] && $next['progress'] === 0 && $next['title'] === $parent['title']
            && $next['content'] === $parent['content'] && $next['background_color'] === '#FECACA', '次回分は内容・色を保持して未完了・進捗0%');
        verify($next['repeat_type'] === $repeatType && $next['repeat_day'] === $repeatDay
            && $next['repeat_timezone'] === 'Asia/Tokyo', '次回分にも繰り返し設定を引き継ぐ');
        $nextDate = (new DateTimeImmutable($next['scheduled_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'));
        verify($nextDate > new DateTimeImmutable() && $nextDate->format('H:i') === '09:15', '指定タイムゾーンの次回時刻を使う');
        if ($repeatType === 'weekly') verify((int) $nextDate->format('w') === $repeatDay, '指定した曜日に繰り返す');
        if ($repeatType === 'monthly') verify((int) $nextDate->format('j') === min($repeatDay, (int) $nextDate->format('t')), '指定日または月末に繰り返す');
        $afterCompletion = activeIds($cookie);
        requestApi('complete_task', 'PATCH', ['id' => $parent['id']], $cookie, 404);
        verify(activeIds($cookie) === $afterCompletion, '二重完了では次回分を増やさない');
        requestApi('restore_task', 'PATCH', ['id' => $parent['id']], $cookie, 200);
        verify(requestApi('complete_task', 'PATCH', ['id' => $parent['id']], $cookie, 200)['next_task'] === null, '同じ履歴を復元して再完了しても次回分は重複しない');
        $editNext = ['id' => $next['id'], 'title' => '次回の編集', 'task_type' => 'deadline', 'scheduled_at' => $next['scheduled_at']];
        verify(requestApi('edit_task', 'PATCH', $editNext, $cookie, 200)['data']['repeat_type'] === $repeatType, '旧形式の編集で繰り返し設定を保持');
        $disabled = requestApi('edit_task', 'PATCH', $editNext + ['repeat_type' => 'none'], $cookie, 200)['data'];
        verify($disabled['repeat_type'] === 'none' && $disabled['repeat_day'] === null && $disabled['repeat_time'] === null, '繰り返しを解除できる');
        verify(requestApi('complete_task', 'PATCH', ['id' => $next['id']], $cookie, 200)['next_task'] === null, '解除後は次回分を作らない');
    }
    $validationTask = requestApi('add_task', 'POST', ['title' => '繰り返し入力の検証'], $cookie, 201)['data'];
    $validationBase = ['repeat_type' => 'daily', 'repeat_day' => null, 'repeat_time' => '09:00', 'repeat_timezone' => 'Asia/Tokyo'];
    $beforeInvalidRepeat = activeIds($cookie);
    foreach ([['repeat_type' => 'yearly'], ['repeat_type' => null], ['repeat_day' => 3], ['repeat_time' => '24:00'],
        ['repeat_type' => 'weekly', 'repeat_day' => 7], ['repeat_type' => 'monthly', 'repeat_day' => 32],
        ['repeat_timezone' => 'invalid'], ['repeat_time' => []],
    ] as $invalid) {
        $input = ['title' => '変更されない繰り返し'] + array_replace($validationBase, $invalid);
        requestApi('add_task', 'POST', $input, $cookie, 422);
        requestApi('edit_task', 'PATCH', ['id' => $validationTask['id']] + $input, $cookie, 422);
    }
    verify(activeIds($cookie) === $beforeInvalidRepeat, '不正な繰り返しで追加・削除しない');
    $periodStart = new DateTimeImmutable('+1 day');
    $period = requestApi('add_task', 'POST', [
        'title' => '期間の繰り返し', 'task_type' => 'period', 'starts_at' => $periodStart->format(DATE_ATOM),
        'scheduled_at' => $periodStart->modify('+2 hours')->format(DATE_ATOM),
    ] + $validationBase, $cookie, 201)['data'];
    $periodNext = requestApi('complete_task', 'PATCH', ['id' => $period['id']], $cookie, 200)['next_task'];
    verify($periodNext['task_type'] === 'period' && strtotime($periodNext['scheduled_at']) - strtotime($periodNext['starts_at']) === 7200
        && new DateTimeImmutable($periodNext['starts_at']) > new DateTimeImmutable(), '次回の期間は長さを維持し開始も未来にする');

    // 次回分を保存できない場合は、元の完了も取り消して未完了を保つ。
    $rollbackTask = requestApi('add_task', 'POST', ['title' => '繰り返しのロールバック'] + $validationBase, $cookie, 201)['data'];
    $pdo->exec("CREATE FUNCTION reject_repeat_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF NEW.repeat_source_id IS NOT NULL AND NEW.title = '繰り返しのロールバック' THEN RAISE EXCEPTION 'test failure'; END IF;
        RETURN NEW; END; \$\$;
        CREATE TRIGGER reject_repeat_test BEFORE INSERT ON tasks FOR EACH ROW EXECUTE FUNCTION reject_repeat_test()");
    requestApi('complete_task', 'PATCH', ['id' => $rollbackTask['id']], $cookie, 500);
    verify(in_array($rollbackTask['id'], activeIds($cookie), true), '次回分の保存失敗時は元のタスクも未完了');
    $pdo->exec('DROP TRIGGER reject_repeat_test ON tasks; DROP FUNCTION reject_repeat_test()');
    verify(requestApi('complete_task', 'PATCH', ['id' => $rollbackTask['id']], $cookie, 200)['next_task'] !== null, '保存失敗後に安全に再試行できる');

    echo "API integration: {$checks} checks passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    fclose($serverLog);
}
