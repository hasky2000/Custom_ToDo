<?php

declare(strict_types=1);

require_once __DIR__ . '/color_palette.php';

// テンプレートは公開ディレクトリ外のJSONで管理する。画面からの編集は受け付けない。
function colorTemplateCatalog(): array
{
    static $catalog = null;
    if ($catalog !== null) return $catalog;

    $source = file_get_contents(__DIR__ . '/color_templates.json');
    $data = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_string($data['default_id'] ?? null)
        || !is_array($data['templates'] ?? null) || $data['templates'] === []) {
        throw new RuntimeException('配色テンプレートの設定を確認してください。');
    }
    $ids = [];
    foreach ($data['templates'] as &$template) {
        if (!is_array($template) || !is_string($template['id'] ?? null)
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $template['id']) !== 1
            || in_array($template['id'], $ids, true) || !is_string($template['name'] ?? null)
            || trim($template['name']) === '' || !is_array($template['colors'] ?? null)) {
            throw new RuntimeException('配色テンプレートのID・名前・配色を確認してください。');
        }
        $colors = $template['colors'];
        if (count($colors) !== count(colorComponentKeys()) || array_diff(array_keys($colors), colorComponentKeys()) !== []) {
            throw new RuntimeException('配色テンプレートにはすべての色を指定してください。');
        }
        foreach ($colors as $key => $value) {
            if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
                throw new RuntimeException('配色テンプレートのカラーコードを確認してください。');
            }
            $colors[$key] = strtoupper($value);
        }
        if (colorPaletteError($colors, $colors) !== null) {
            throw new RuntimeException('配色テンプレートの文字色と背景色を確認してください。');
        }
        $template['colors'] = $colors;
        $ids[] = $template['id'];
    }
    unset($template);
    if (!in_array($data['default_id'], $ids, true)) {
        throw new RuntimeException('初期配色のテンプレートが見つかりません。');
    }
    $catalog = $data;
    return $catalog;
}

function defaultColorTemplate(): array
{
    $catalog = colorTemplateCatalog();
    foreach ($catalog['templates'] as $template) {
        if ($template['id'] === $catalog['default_id']) return $template;
    }
    throw new RuntimeException('初期配色のテンプレートが見つかりません。');
}
