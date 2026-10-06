-- 既存の配色を保持したまま、枠・ブラー・フォントなどの表示設定を追加する。
ALTER TABLE user_colors
    ADD COLUMN IF NOT EXISTS appearance JSONB NOT NULL DEFAULT '{}'::jsonb;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'user_colors'::regclass AND conname = 'user_colors_appearance_object'
    ) THEN
        ALTER TABLE user_colors ADD CONSTRAINT user_colors_appearance_object
            CHECK (jsonb_typeof(appearance) = 'object');
    END IF;
END
$$;
