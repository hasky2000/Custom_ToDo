UPDATE tasks
SET progress = 0
WHERE progress IS NULL;

ALTER TABLE tasks
    ALTER COLUMN progress SET DEFAULT 0,
    ALTER COLUMN progress SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'tasks_progress_range'
    ) THEN
        ALTER TABLE tasks
            ADD CONSTRAINT tasks_progress_range
            CHECK (progress BETWEEN 0 AND 100);
    END IF;
END
$$;
