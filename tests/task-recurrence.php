<?php

declare(strict_types=1);

$appDirectory = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__) . '/src';
require_once $appDirectory . '/app/task_recurrence.php';
$checks = 0;
function checkRecurrence(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}
function repeatingTask(string $type = 'daily', ?int $day = null, string $time = '09:00', string $zone = 'Asia/Tokyo', array $extra = []): array
{
    return array_replace([
        'repeat_type' => $type, 'repeat_day' => $day, 'repeat_time' => $time, 'repeat_timezone' => $zone,
        'task_type' => 'unscheduled', 'starts_at' => null, 'scheduled_at' => null,
    ], $extra);
}

$base = repeatingTask();
$normalized = normalizeTaskRecurrence($base);
checkRecurrence($normalized['repeat_time'] === '09:00:00', '分単位の時刻を正規化する');
checkRecurrence(normalizeTaskRecurrence([], $normalized) === $normalized, '旧形式の編集で設定を保持する');
checkRecurrence(normalizeTaskRecurrence([])['repeat_type'] === 'none', '旧形式の追加は繰り返しなし');
checkRecurrence(normalizeTaskRecurrence(['repeat_type' => 'none'], $normalized)['repeat_time'] === null, '繰り返しを解除できる');
foreach ([
    ['repeat_type' => 'yearly'], ['repeat_type' => []], ['repeat_day' => 1],
    ['repeat_type' => 'weekly', 'repeat_day' => 7], ['repeat_type' => 'weekly', 'repeat_day' => '1'],
    ['repeat_type' => 'monthly', 'repeat_day' => 0], ['repeat_type' => 'monthly', 'repeat_day' => 32],
    ['repeat_time' => ''], ['repeat_time' => '24:00'], ['repeat_time' => '9:00'], ['repeat_time' => '09:60'],
    ['repeat_time' => '09:00:01'], ['repeat_time' => null], ['repeat_time' => []],
    ['repeat_timezone' => 'invalid'], ['repeat_timezone' => '+09:00'], ['repeat_timezone' => null],
] as $invalid) {
    try {
        normalizeTaskRecurrence(array_replace($base, $invalid));
        checkRecurrence(false, '不正な繰り返し設定を受け付けない');
    } catch (InvalidArgumentException) {
        checkRecurrence(true, '不正な設定を拒否する');
    }
}

$cases = [
    [$base, '2026-11-10T08:00:00+09:00', '2026-11-10T09:00:00+09:00'],
    [$base, '2026-11-10T09:00:00+09:00', '2026-11-11T09:00:00+09:00'],
    [repeatingTask('weekly', 3), '2026-11-10T15:00:00+09:00', '2026-11-11T09:00:00+09:00'],
    [repeatingTask('weekly', 3), '2026-11-11T09:00:00+09:00', '2026-11-18T09:00:00+09:00'],
    [repeatingTask('weekly', 0), '2026-11-10T15:00:00+09:00', '2026-11-15T09:00:00+09:00'],
    [repeatingTask('monthly', 31), '2026-01-31T10:00:00+09:00', '2026-02-28T09:00:00+09:00'],
    [repeatingTask('monthly', 31), '2028-01-31T10:00:00+09:00', '2028-02-29T09:00:00+09:00'],
    [repeatingTask('monthly', 31), '2026-02-28T10:00:00+09:00', '2026-03-31T09:00:00+09:00'],
    [repeatingTask('monthly', 1), '2026-12-31T23:00:00+09:00', '2027-01-01T09:00:00+09:00'],
    [repeatingTask('daily', null, '09:00', 'America/New_York'), '2026-03-07T10:00:00-05:00', '2026-03-08T09:00:00-04:00'],
    [repeatingTask('daily', null, '02:30', 'America/New_York'), '2026-03-07T10:00:00-05:00', '2026-03-08T03:30:00-04:00'],
    [repeatingTask('daily', null, '02:30', 'America/New_York'), '2026-03-08T04:00:00-04:00', '2026-03-09T02:30:00-04:00'],
    [repeatingTask('daily', null, '09:00', 'America/New_York'), '2026-10-31T10:00:00-04:00', '2026-11-01T09:00:00-05:00'],
    [repeatingTask(extra: ['task_type' => 'deadline', 'scheduled_at' => '2026-11-20T09:00:00+09:00']), '2026-11-10T08:00:00+09:00', '2026-11-21T09:00:00+09:00'],
    [repeatingTask(extra: ['task_type' => 'deadline', 'scheduled_at' => '2026-11-01T09:00:00+09:00']), '2026-11-10T20:00:00+09:00', '2026-11-11T09:00:00+09:00'],
];
foreach ($cases as [$task, $now, $expected]) {
    $schedule = nextRecurringTaskSchedule($task, new DateTimeImmutable($now));
    checkRecurrence(new DateTimeImmutable($schedule['scheduled_at']) == new DateTimeImmutable($expected), "次回日時が正しい: {$expected}");
    checkRecurrence($schedule['task_type'] === 'deadline' && $schedule['starts_at'] === null, '日時なし・締切タスクの次回は期限のみ');
}
$period = repeatingTask(extra: ['task_type' => 'period', 'starts_at' => '2026-11-10T08:00:00+09:00', 'scheduled_at' => '2026-11-10T10:00:00+09:00']);
$schedule = nextRecurringTaskSchedule($period, new DateTimeImmutable('2026-11-10T15:00:00+09:00'));
checkRecurrence(new DateTimeImmutable($schedule['starts_at']) == new DateTimeImmutable('2026-11-11T07:00:00+09:00'), '期間の長さを保持');
$period['starts_at'] = '2026-11-08T10:00:00+09:00';
$schedule = nextRecurringTaskSchedule($period, new DateTimeImmutable('2026-11-10T15:00:00+09:00'));
checkRecurrence(new DateTimeImmutable($schedule['starts_at']) > new DateTimeImmutable('2026-11-10T15:00:00+09:00'), '長い期間でも次の開始を未来にする');
checkRecurrence(nextRecurringTaskSchedule(['repeat_type' => 'none']) === null, '繰り返しなしでは次回を作らない');
echo "Recurrence schedule: {$checks} checks passed.\n";
