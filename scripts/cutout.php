<?php

declare(strict_types=1);

/**
 * Cuts a building out of a flat background (renders saved as JPG): flood
 * fill from the edges with what looks like the background colour, soft
 * edge with the background tint taken off, then crop and scale down.
 * Used by import-sprites.php for .jpg files.
 */

const CUTOUT_SOLID = 34;   // colour distance: background below this
const CUTOUT_EDGE = 72;    // fading edge up to this
const CUTOUT_WIDTH = 320;  // pixels wide at most once cropped

/** Truecolor image with alpha: the building alone, cropped and scaled. */
function cutout(GdImage $source): GdImage
{
    $width = imagesx($source);
    $height = imagesy($source);
    $rgb = static fn (int $c): array => [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];

    // Background colour: median of the border pixels.
    $border = [];
    for ($x = 0; $x < $width; $x += 4) {
        $border[] = $rgb(imagecolorat($source, $x, 0));
        $border[] = $rgb(imagecolorat($source, $x, $height - 1));
    }
    for ($y = 0; $y < $height; $y += 4) {
        $border[] = $rgb(imagecolorat($source, 0, $y));
        $border[] = $rgb(imagecolorat($source, $width - 1, $y));
    }
    $background = [];
    for ($k = 0; $k < 3; $k++) {
        $values = array_column($border, $k);
        sort($values);
        $background[$k] = $values[intdiv(count($values), 2)];
    }
    $distance = static function (array $c) use ($background): float {
        return sqrt(($c[0] - $background[0]) ** 2 + ($c[1] - $background[1]) ** 2 + ($c[2] - $background[2]) ** 2);
    };

    // Flood fill from the edges: what is reached and close to the background is background.
    $isBackground = array_fill(0, $width * $height, false);
    $queue = new SplQueue();
    $push = static function (int $x, int $y) use (&$isBackground, $queue, $width, $source, $rgb, $distance): void {
        $i = $y * $width + $x;
        if (!$isBackground[$i] && $distance($rgb(imagecolorat($source, $x, $y))) < CUTOUT_SOLID) {
            $isBackground[$i] = true;
            $queue->enqueue($i);
        }
    };
    for ($x = 0; $x < $width; $x++) {
        $push($x, 0);
        $push($x, $height - 1);
    }
    for ($y = 0; $y < $height; $y++) {
        $push(0, $y);
        $push($width - 1, $y);
    }
    while (!$queue->isEmpty()) {
        $i = $queue->dequeue();
        [$x, $y] = [$i % $width, intdiv($i, $width)];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            [$nx, $ny] = [$x + $dx, $y + $dy];
            if ($nx >= 0 && $ny >= 0 && $nx < $width && $ny < $height) {
                $push($nx, $ny);
            }
        }
    }

    // Copy with alpha: background clear, its neighbours fading, background tint taken off them.
    $out = imagecreatetruecolor($width, $height);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    [$minX, $minY, $maxX, $maxY] = [$width, $height, 0, 0];
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $i = $y * $width + $x;
            if ($isBackground[$i]) {
                continue;
            }
            $color = $rgb(imagecolorat($source, $x, $y));
            $alpha = 1.0;
            $nearBackground = ($x > 0 && $isBackground[$i - 1]) || ($x < $width - 1 && $isBackground[$i + 1])
                || ($y > 0 && $isBackground[$i - $width]) || ($y < $height - 1 && $isBackground[$i + $width]);
            if ($nearBackground) {
                $alpha = max(0.0, min(1.0, ($distance($color) - CUTOUT_SOLID) / (CUTOUT_EDGE - CUTOUT_SOLID)));
                if ($alpha <= 0.02) {
                    continue;
                }
                for ($k = 0; $k < 3; $k++) {
                    $color[$k] = max(0, min(255, (int) round(($color[$k] - (1 - $alpha) * $background[$k]) / $alpha)));
                }
            }
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $color[0], $color[1], $color[2], (int) round(127 * (1 - $alpha))));
            [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x), max($maxY, $y)];
        }
    }

    // Crop, then scale to CUTOUT_WIDTH at most.
    $cropW = $maxX - $minX + 1;
    $cropH = $maxY - $minY + 1;
    $scale = min(1.0, CUTOUT_WIDTH / $cropW);
    $finalW = max(1, (int) round($cropW * $scale));
    $finalH = max(1, (int) round($cropH * $scale));
    $final = imagecreatetruecolor($finalW, $finalH);
    imagealphablending($final, false);
    imagesavealpha($final, true);
    imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
    imagecopyresampled($final, $out, 0, 0, $minX, $minY, $finalW, $finalH, $cropW, $cropH);

    return $final;
}
