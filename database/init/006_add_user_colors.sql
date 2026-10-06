-- 配色はアカウントごとに保存する。未設定の項目には共通テンプレートを使う。
CREATE TABLE IF NOT EXISTS user_colors (
    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    colors JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_colors_object CHECK (jsonb_typeof(colors) = 'object')
);
