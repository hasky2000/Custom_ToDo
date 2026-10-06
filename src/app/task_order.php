<?php

declare(strict_types=1);

// 追加・復元・並べ替えで同じ順番を同時に割り当てないよう、ユーザー単位でロックする。
// 呼び出し元でトランザクションを開始してから使用する。
function lockTaskOrder(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
    $statement->execute([':user_id' => $userId]);
}

function nextTaskOrder(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tasks WHERE user_id = :user_id'
    );
    $statement->execute([':user_id' => $userId]);

    return (int) $statement->fetchColumn();
}
