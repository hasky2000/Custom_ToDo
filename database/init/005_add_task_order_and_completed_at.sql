ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS sort_order BIGINT,
    ADD COLUMN IF NOT EXISTS completed_at TIMESTAMPTZ;

-- 既存のタスクはこれまでの表示順を保つ。
WITH ordered_tasks AS (
    SELECT id, ROW_NUMBER() OVER (
        PARTITION BY user_id
        ORDER BY scheduled_at ASC NULLS LAST, created_at DESC, id DESC
    ) AS position
    FROM tasks
)
UPDATE tasks
SET sort_order = ordered_tasks.position
FROM ordered_tasks
WHERE tasks.id = ordered_tasks.id AND tasks.sort_order IS NULL;

ALTER TABLE tasks
    ALTER COLUMN sort_order SET NOT NULL,
    ALTER COLUMN sort_order SET DEFAULT 0;

-- 以前の完了日時は updated_at に記録されている。
UPDATE tasks SET completed_at = updated_at
WHERE completed = TRUE AND completed_at IS NULL;

CREATE INDEX IF NOT EXISTS tasks_user_order_index ON tasks(user_id, sort_order, id);
