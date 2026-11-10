<?php

declare(strict_types=1);

/** 画面とAPIで共通の、タスクのライン色16色。 */
function taskColorPalette(): array
{
    static $palette = null;
    if ($palette === null) {
        $palette = json_decode(file_get_contents(__DIR__ . '/task_colors.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    return $palette;
}
