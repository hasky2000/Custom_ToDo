-- scheduled_at は従来の予定日時を保持し、以後は締切（終了日時）として扱う。
ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS task_type TEXT,
    ADD COLUMN IF NOT EXISTS starts_at TIMESTAMPTZ;

UPDATE tasks
SET task_type = CASE WHEN scheduled_at IS NULL THEN 'unscheduled' ELSE 'deadline' END
WHERE task_type IS NULL;

ALTER TABLE tasks
    ALTER COLUMN task_type SET DEFAULT 'unscheduled',
    ALTER COLUMN task_type SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'tasks'::regclass AND conname = 'tasks_schedule_type_check'
    ) THEN
        ALTER TABLE tasks ADD CONSTRAINT tasks_schedule_type_check CHECK (
            (task_type = 'unscheduled' AND starts_at IS NULL AND scheduled_at IS NULL)
            OR (task_type = 'deadline' AND starts_at IS NULL AND scheduled_at IS NOT NULL)
            OR (task_type = 'period' AND starts_at IS NOT NULL AND scheduled_at IS NOT NULL AND starts_at < scheduled_at)
        );
    END IF;
END
$$;
