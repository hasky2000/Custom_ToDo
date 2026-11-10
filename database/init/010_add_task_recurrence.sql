ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS repeat_type VARCHAR(7) NOT NULL DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS repeat_day SMALLINT,
    ADD COLUMN IF NOT EXISTS repeat_time TIME(0),
    ADD COLUMN IF NOT EXISTS repeat_timezone VARCHAR(64),
    ADD COLUMN IF NOT EXISTS repeat_source_id INTEGER UNIQUE REFERENCES tasks(id) ON DELETE SET NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'tasks'::regclass AND conname = 'tasks_recurrence_check') THEN
        ALTER TABLE tasks ADD CONSTRAINT tasks_recurrence_check CHECK (
            (repeat_type = 'none' AND repeat_day IS NULL AND repeat_time IS NULL AND repeat_timezone IS NULL)
            OR (repeat_type IN ('daily', 'weekly', 'monthly')
                AND repeat_time IS NOT NULL AND repeat_time < TIME '24:00:00'
                AND EXTRACT(SECOND FROM repeat_time) = 0 AND repeat_timezone IS NOT NULL
                AND ((repeat_type = 'daily' AND repeat_day IS NULL)
                    OR (repeat_type = 'weekly' AND repeat_day IS NOT NULL AND repeat_day BETWEEN 0 AND 6)
                    OR (repeat_type = 'monthly' AND repeat_day IS NOT NULL AND repeat_day BETWEEN 1 AND 31)))
        );
    END IF;
END
$$;
