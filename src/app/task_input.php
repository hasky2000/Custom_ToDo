<?php

declare(strict_types=1);

// PHPのtrim()では除去されない全角スペースなども、入力の前後から取り除く。
function trimTaskText(string $value): string
{
    return preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $value) ?? '';
}

function parseTaskDate(mixed $value, string $label): ?DateTimeImmutable
{
    if ($value !== null && !is_string($value)) {
        throw new InvalidArgumentException("{$label}は文字列で指定してください。");
    }
    $value = is_string($value) ? trimTaskText($value) : '';
    if ($value === '') return null;

    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception) {
        throw new InvalidArgumentException("{$label}の形式が正しくありません。");
    }
    if (DateTimeImmutable::getLastErrors() !== false) {
        throw new InvalidArgumentException("{$label}の形式が正しくありません。");
    }
    return $date;
}

// 追加と編集で同じ入力ルールを使う。変更しない既存日時は、過ぎていても保持できる。
function normalizeTaskInput(array $input, ?array $existingTask = null): array
{
    $title = is_string($input['title'] ?? null) ? trimTaskText($input['title']) : '';
    if ($title === '') {
        throw new InvalidArgumentException('タイトルを入力してください。空白のみでは登録できません。');
    }

    $content = $input['content'] ?? null;
    if ($content !== null && !is_string($content)) {
        throw new InvalidArgumentException('内容は文字列で入力してください。');
    }
    $content = is_string($content) ? trimTaskText($content) : null;
    $content = $content === '' ? null : $content;
    if ($content !== null && preg_match_all('/./us', $content) > 511) {
        throw new InvalidArgumentException('内容は511文字以内で入力してください。');
    }

    $startsAt = parseTaskDate($input['starts_at'] ?? null, '開始日時');
    $scheduledAt = parseTaskDate($input['scheduled_at'] ?? null, '締切日時');
    // 種類を送らない旧画面からのリクエストも、従来どおり受け付ける。
    $taskType = array_key_exists('task_type', $input) ? $input['task_type']
        : ($startsAt !== null ? 'period' : ($scheduledAt !== null ? 'deadline' : 'unscheduled'));
    if (!in_array($taskType, ['unscheduled', 'deadline', 'period'], true)) {
        throw new InvalidArgumentException('タスクの種類を選択してください。');
    }
    if ($taskType === 'unscheduled' && ($startsAt !== null || $scheduledAt !== null)) {
        throw new InvalidArgumentException('「日時なし」では開始日時・締切日時を指定しないでください。');
    }
    if ($taskType === 'deadline' && ($startsAt !== null || $scheduledAt === null)) {
        throw new InvalidArgumentException('「期限のみ」では締切日時だけを指定してください。');
    }
    if ($taskType === 'period' && ($startsAt === null || $scheduledAt === null)) {
        throw new InvalidArgumentException('「開始・終了あり」では開始日時と終了日時を指定してください。');
    }
    if ($startsAt !== null && $scheduledAt !== null && $startsAt >= $scheduledAt) {
        throw new InvalidArgumentException('終了日時は開始日時より後を指定してください。');
    }
    $now = new DateTimeImmutable();
    foreach (['starts_at' => $startsAt, 'scheduled_at' => $scheduledAt] as $key => $date) {
        if ($date === null || $date >= $now) continue;
        $unchanged = $existingTask !== null && $existingTask['task_type'] === $taskType
            && $existingTask[$key] !== null && (new DateTimeImmutable($existingTask[$key])) == $date;
        if (!$unchanged) {
            $label = $key === 'starts_at' ? '開始日時' : '締切日時';
            throw new InvalidArgumentException("{$label}は現在時刻以降を指定してください。");
        }
    }

    return [
        'title' => $title, 'content' => $content, 'task_type' => $taskType,
        'starts_at' => $startsAt?->format('Y-m-d\TH:i:s.uP'),
        'scheduled_at' => $scheduledAt?->format('Y-m-d\TH:i:s.uP'),
    ];
}
