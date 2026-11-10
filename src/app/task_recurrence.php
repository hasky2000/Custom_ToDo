<?php

declare(strict_types=1);

require_once __DIR__ . '/task_order.php';

function normalizeTaskRecurrence(array $input, ?array $existingTask = null): array
{
    $none = ['repeat_type' => 'none', 'repeat_day' => null, 'repeat_time' => null, 'repeat_timezone' => null];
    // 旧クライアントからの編集では、保存済みの繰り返し設定を消さない。
    if (!array_key_exists('repeat_type', $input)) {
        return $existingTask === null ? $none : array_replace($none, array_intersect_key($existingTask, $none));
    }
    $type = $input['repeat_type'];
    if (!in_array($type, ['none', 'daily', 'weekly', 'monthly'], true)) {
        throw new InvalidArgumentException('繰り返しの単位を選択してください。');
    }
    if ($type === 'none') return $none;

    $day = $input['repeat_day'] ?? null;
    if (($type === 'daily' && $day !== null)
        || ($type === 'weekly' && (!is_int($day) || $day < 0 || $day > 6))
        || ($type === 'monthly' && (!is_int($day) || $day < 1 || $day > 31))) {
        throw new InvalidArgumentException('繰り返す日付・曜日を正しく指定してください。');
    }
    $time = $input['repeat_time'] ?? null;
    if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?$/', $time)) {
        throw new InvalidArgumentException('繰り返す時刻を指定してください。');
    }
    $timezone = $input['repeat_timezone'] ?? null;
    if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        throw new InvalidArgumentException('繰り返しのタイムゾーンが正しくありません。');
    }
    return ['repeat_type' => $type, 'repeat_day' => $day, 'repeat_time' => substr($time, 0, 5) . ':00', 'repeat_timezone' => $timezone];
}

/** 現在のタスクの締切と現在時刻の両方より後になる、次の予定を返す。 */
function nextRecurringTaskSchedule(array $task, ?DateTimeImmutable $now = null): ?array
{
    $type = $task['repeat_type'] ?? 'none';
    if ($type === 'none') return null;
    $zone = new DateTimeZone($task['repeat_timezone']);
    $utc = new DateTimeZone('UTC');
    $now = ($now ?? new DateTimeImmutable())->setTimezone($utc);
    $end = $task['scheduled_at'] === null ? $now : new DateTimeImmutable($task['scheduled_at']);
    $duration = null;
    $threshold = $now;
    if ($task['task_type'] === 'period') {
        $start = (new DateTimeImmutable($task['starts_at']))->setTimezone($utc);
        $duration = $start->diff($end->setTimezone($utc));
        // 期間タスクは、次の開始日時も過去にならないようにする。
        $threshold = $now->add($duration);
    }
    if ($end > $threshold) $threshold = $end;
    $threshold = $threshold->setTimezone($zone);
    [$hour, $minute] = array_map('intval', explode(':', $task['repeat_time']));

    if ($type === 'monthly') {
        $month = $threshold->modify('first day of this month')->setTime(0, 0);
        for ($offset = 0; $offset < 3; $offset++) {
            $candidate = $month->modify("+{$offset} months");
            $day = min((int) $task['repeat_day'], (int) $candidate->format('t'));
            $candidate = $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('n'), $day)->setTime($hour, $minute);
            if ($candidate > $threshold) break;
        }
    } else {
        $date = $threshold->setTime(0, 0);
        for ($offset = 0; $offset < 9; $offset++) {
            $candidate = $date->modify("+{$offset} days");
            if ($type === 'weekly' && (int) $candidate->format('w') !== (int) $task['repeat_day']) continue;
            $candidate = $candidate->setTime($hour, $minute);
            if ($candidate > $threshold) break;
        }
    }
    if ($candidate <= $threshold) throw new LogicException('次の繰り返し日時を計算できませんでした。');
    $nextStart = $duration === null ? null : $candidate->setTimezone($utc)->sub($duration);
    return [
        'task_type' => $duration === null ? 'deadline' : 'period',
        'starts_at' => $nextStart?->format('Y-m-d\TH:i:s.uP'),
        'scheduled_at' => $candidate->format('Y-m-d\TH:i:s.uP'),
    ];
}

/** 完了と次回分の作成を、同じトランザクションで確定する。 */
function completeTaskAndRepeat(PDO $pdo, int $userId, int $id): ?array
{
    $pdo->beginTransaction();
    try {
        lockTaskOrder($pdo, $userId);
        $statement = $pdo->prepare(
            'UPDATE tasks SET completed = TRUE, progress = 100, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND completed = FALSE
             RETURNING id, title, content, completed, progress, background_color, task_type, starts_at, scheduled_at,
                       repeat_type, repeat_day, repeat_time, repeat_timezone, created_at, updated_at'
        );
        $statement->execute([':id' => $id, ':user_id' => $userId]);
        $task = $statement->fetch();
        if ($task === false) {
            $pdo->rollBack();
            return null;
        }
        $nextTask = null;
        $schedule = nextRecurringTaskSchedule($task);
        if ($schedule !== null) {
            // アーカイブから戻した同じ回を再完了しても、次回分を重複させない。
            $statement = $pdo->prepare('SELECT id FROM tasks WHERE repeat_source_id = :id AND user_id = :user_id');
            $statement->execute([':id' => $id, ':user_id' => $userId]);
            if ($statement->fetch() === false) {
                $statement = $pdo->prepare(
                    'INSERT INTO tasks (user_id, title, content, background_color, task_type, starts_at, scheduled_at,
                                        repeat_type, repeat_day, repeat_time, repeat_timezone, repeat_source_id, sort_order)
                     VALUES (:user_id, :title, :content, :color, :type, :start, :end, :repeat_type, :day, :time, :zone, :source, :sort_order)
                     RETURNING id, title, content, completed, progress, background_color, task_type, starts_at, scheduled_at,
                               repeat_type, repeat_day, repeat_time, repeat_timezone, created_at, updated_at'
                );
                $statement->execute([
                    ':user_id' => $userId, ':title' => $task['title'], ':content' => $task['content'], ':color' => $task['background_color'],
                    ':type' => $schedule['task_type'], ':start' => $schedule['starts_at'], ':end' => $schedule['scheduled_at'],
                    ':repeat_type' => $task['repeat_type'], ':day' => $task['repeat_day'], ':time' => $task['repeat_time'],
                    ':zone' => $task['repeat_timezone'], ':source' => $id, ':sort_order' => nextTaskOrder($pdo, $userId),
                ]);
                $nextTask = $statement->fetch();
            }
        }
        $pdo->commit();
        return ['data' => $task, 'next_task' => $nextTask];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
