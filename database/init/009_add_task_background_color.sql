-- NULLは色のラインを表示しない。保存項目名は既存互換のため維持する。
ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS background_color VARCHAR(7)
        CHECK (background_color IS NULL OR background_color ~ '^#[0-9A-F]{6}$');
