<?php

declare(strict_types=1);

// Usage: php scripts/make-premium-sprite.php <type> [<premium type>]
// Default picture of a premium variant made from the normal one, until a real
// picture is imported: golden tint on the building, more colour, a gold star.
//   php scripts/make-premium-sprite.php rabbit_hutch   -> premium_rabbit_hutch-1.webp
// Then run php scripts/import-sprites.php (no argument needed) to update manifest.json.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$type = $argv[1] ?? null;
if ($type === null) {
    fwrite(STDERR, "Usage : php scripts/make-premium-sprite.php <type> [<type premium>]\n");
    exit(1);
}
$target = $argv[2] ?? "premium_{$type}";
$dir = dirname(__DIR__) . '/public/assets/img/buildings';
$source = imagecreatefromwebp("{$dir}/{$type}-1.webp") ?: exit("Image introuvable : {$type}-1.webp\n");
$width = imagesx($source);
$height = imagesy($source);
$out = imagecreatetruecolor($width, $height);
imagealphablending($out, false);
imagesavealpha($out, true);

for ($y = 0; $y < $height; $y++) {
    // Gold mostly on the building (upper part), little on the ground.
    $tint = 0.55 * max(0.0, 1 - $y / ($height * 0.85));
    for ($x = 0; $x < $width; $x++) {
        $rgba = imagecolorat($source, $x, $y);
        $alpha = ($rgba >> 24) & 0x7F;
        [$r, $g, $b] = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
        // A little more saturation, then a warm golden blend.
        $grey = ($r + $g + $b) / 3;
        [$r, $g, $b] = array_map(static fn (int $c): float => $grey + ($c - $grey) * 1.2, [$r, $g, $b]);
        [$r, $g, $b] = [$r + (255 - $r) * $tint * 0.9, $g + (205 - $g) * $tint, $b + (70 - $b) * $tint];
        $clamp = static fn (float $c): int => max(0, min(255, (int) round($c)));
        imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $clamp($r), $clamp($g), $clamp($b), $alpha));
    }
}

// Gold star with a dark outline above the roof.
imagealphablending($out, true);
$cx = (int) round($width * 0.5);
$cy = 13;
$star = static function (float $outer, float $inner) use ($cx, $cy): array {
    $points = [];
    for ($i = 0; $i < 10; $i++) {
        $radius = $i % 2 === 0 ? $outer : $inner;
        $angle = -M_PI / 2 + $i * M_PI / 5;
        $points[] = (int) round($cx + $radius * cos($angle));
        $points[] = (int) round($cy + $radius * sin($angle));
    }
    return $points;
};
imagefilledpolygon($out, $star(13, 6), imagecolorallocate($out, 120, 70, 10));
imagefilledpolygon($out, $star(11, 5), imagecolorallocate($out, 255, 205, 40));
imagefilledpolygon($out, $star(5, 2), imagecolorallocate($out, 255, 245, 190));

imagewebp($out, "{$dir}/{$target}-1.webp", 88);
echo "{$target}-1.webp ({$width}×{$height})\n";
