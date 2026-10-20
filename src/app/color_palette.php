<?php

declare(strict_types=1);

function colorDistance(string $first, string $second): int
{
    $distance = 0;
    foreach ([1, 3, 5] as $offset) {
        $distance += abs(hexdec(substr($first, $offset, 2)) - hexdec(substr($second, $offset, 2)));
    }
    return $distance;
}

function colorComponentKeys(): array
{
    return [
        'page', 'surface', 'header', 'card', 'input', 'primary', 'primary_text',
        'secondary', 'secondary_text', 'text', 'border', 'progress', 'navigation', 'navigation_active',
    ];
}

function colorPaletteError(array $colors, array $defaults): ?string
{
    // palette-format.js と同じ組み合わせを、APIへ直接保存する場合にも検証する。
    $textBackgrounds = [
        'text' => ['page', 'surface', 'header', 'card', 'input', 'secondary', 'navigation', 'navigation_active'],
        'primary_text' => ['primary'],
        'secondary_text' => ['secondary'],
    ];
    $palette = array_replace($defaults, $colors);
    foreach ($textBackgrounds as $text => $backgrounds) {
        foreach ($backgrounds as $background) {
            if (colorDistance($palette[$text], $palette[$background]) < 10) {
                return '文字色と背景色が近すぎます。RGB各値の差の絶対値の合計を10以上にしてください。';
            }
        }
    }
    return null;
}
