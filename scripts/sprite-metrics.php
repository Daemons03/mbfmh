<?php

declare(strict_types=1);

/**
 * Measures building sprites (PNG or WebP with transparency) for the isometric map:
 * leftmost, rightmost and bottom opaque points, i.e. the left, right and
 * bottom corners of the ground diamond. Prints JSON {file: metrics}.
 *
 *   php scripts/sprite-metrics.php public/assets/img/buildings/*.webp
 */

$result = [];
foreach (array_slice($argv, 1) as $path) {
    $image = str_ends_with($path, '.webp') ? imagecreatefromwebp($path) : imagecreatefrompng($path);
    $w = imagesx($image);
    $h = imagesy($image);
    $opaque = static fn (int $x, int $y): bool => ((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 40;

    $left = $right = $bottom = null;
    for ($x = 0; $x < $w && $left === null; $x++) {
        $ys = array_filter(range(0, $h - 1), static fn (int $y): bool => $opaque($x, $y));
        if ($ys) {
            $left = [$x, array_sum($ys) / count($ys)];
        }
    }
    for ($x = $w - 1; $x >= 0 && $right === null; $x--) {
        $ys = array_filter(range(0, $h - 1), static fn (int $y): bool => $opaque($x, $y));
        if ($ys) {
            $right = [$x + 1, array_sum($ys) / count($ys)];
        }
    }
    for ($y = $h - 1; $y >= 0 && $bottom === null; $y--) {
        $xs = array_filter(range(0, $w - 1), static fn (int $x): bool => $opaque($x, $y));
        if ($xs) {
            $bottom = [array_sum($xs) / count($xs), $y + 1];
        }
    }
    $result[basename($path)] = [
        'width' => $w, 'height' => $h,
        'left' => array_map(static fn ($v) => round($v, 1), $left),
        'right' => array_map(static fn ($v) => round($v, 1), $right),
        'bottom' => array_map(static fn ($v) => round($v, 1), $bottom),
    ];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
