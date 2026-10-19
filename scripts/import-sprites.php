<?php

declare(strict_types=1);

/**
 * Imports building pictures named the player's way (poule_premium_2.png,
 * marche_1.png, terrain_a_acheter.png...) into public/assets/img/buildings
 * as <type>-<tier>.webp (smaller than PNG, transparency kept), then measures
 * every picture there and writes manifest.json (size and ground diamond
 * corners, see sprite-metrics.php).
 *
 *   php scripts/import-sprites.php asset-a-integrer/all
 *   php scripts/import-sprites.php images/trier --levels
 *
 * --levels: the number after the name is the building level the picture
 * starts at (moulin_4.png: from level 4), saved as <type>-L<level>.webp;
 * without it, the number is the visual tier 1-3 (<type>-<tier>.webp).
 * JPG renders on a flat background are cut out first (cutout.php).
 *
 * Unknown names are listed and skipped. Re-run it after adding pictures;
 * without a folder it only measures the pictures and rewrites manifest.json.
 */

const WEBP_QUALITY = 88;

// French prefix => building type ("land_locked" = land not bought yet).
const NAMES = [
    'champ' => 'field', 'champ_fertile' => 'fertile_field', 'champ_premium' => 'premium_field',
    'poule' => 'chicken_coop', 'poule_premium' => 'premium_chicken_coop', 'poule_plein_air' => 'free_range_chicken_coop',
    'poulailler' => 'chicken_coop', 'poulailler_premium' => 'premium_chicken_coop', 'poulailler_plein_air' => 'free_range_chicken_coop',
    'porcherie' => 'pigsty', 'porcherie_premium' => 'premium_pigsty', 'porcherie_plein_air' => 'free_range_pigsty',
    'etable' => 'cowshed', 'etable_premium' => 'premium_cowshed', 'etable_plein_air' => 'free_range_cowshed',
    'chevre' => 'goat_stable', 'chevre_premium' => 'premium_goat_stable', 'chevre_plein_air' => 'free_range_goat_stable',
    'canard' => 'duck_coop', 'canard_premium' => 'premium_duck_coop', 'canard_plein_air' => 'free_range_duck_coop',
    'clapier' => 'rabbit_hutch', 'clapier_premium' => 'premium_rabbit_hutch',
    'mouton' => 'sheep_pen', 'mouton_premium' => 'premium_sheep_pen',
    'pommerai' => 'apple_orchard', 'pommerai_premium' => 'premium_apple_orchard', 'pommerai_plein_air' => 'free_range_apple_orchard',
    'pommerai_fertile' => 'fertile_apple_orchard',
    'ceriserai' => 'cherry_orchard', 'cerise_premium' => 'premium_cherry_orchard', 'cerise_plein_air' => 'free_range_cherry_orchard',
    'cerise_fertile' => 'fertile_cherry_orchard',
    'amanderaie' => 'almond_orchard', 'amande_premium' => 'premium_almond_orchard', 'amande_plein_air' => 'free_range_almond_orchard',
    'amande_fertile' => 'fertile_almond_orchard',
    'pecherai' => 'peach_orchard', 'peche_premium' => 'premium_peach_orchard', 'peche_plein_air' => 'free_range_peach_orchard',
    'peche_fertile' => 'fertile_peach_orchard',
    'prairie' => 'meadow', 'prairie_premium' => 'premium_meadow',
    'moulin' => 'windmill', 'moulin_premium' => 'premium_windmill',
    'silo' => 'silo', 'composteur' => 'composter', 'ruche' => 'beehive', 'ruche_premium' => 'premium_beehive',
    'laiterie' => 'dairy', 'confiture' => 'jam_kitchen', 'fabrique_artisanale' => 'manufactory', 'atelier_couture' => 'sewing_workshop',
    'maison' => 'house', 'habitation_premium' => 'premium_house', 'maison_premium' => 'premium_house', 'grange' => 'barn', 'ferme' => 'farmhouse', 'corps' => 'farmhouse',
    'marche' => 'market', 'concour_animal' => 'show_animal', 'bibliotheque' => 'library', 'grange_exterieur' => 'external_barn',
    'cafe_boutique' => 'cafe',
    'deco_20bo_3x3' => 'duck_pond', 'deco_20bo_3x3_bis' => 'gazebo', 'deco_47bo-size6x3' => 'wind_pump',
    'deco_100bo_6x6' => 'swimming_pond', 'deco_grosse_100bo_6x6_deux' => 'lantern_garden',
    'terrain_a_acheter' => 'land_locked',
];

require __DIR__ . '/cutout.php';

$byLevel = in_array('--levels', $argv, true);
$source = rtrim(array_values(array_diff(array_slice($argv, 1), ['--levels']))[0] ?? '', '/');
$target = dirname(__DIR__) . '/public/assets/img/buildings';
if ($source !== '' && !is_dir($source)) {
    fwrite(STDERR, "Usage: php scripts/import-sprites.php [<folder> [--levels]]   (no folder: only rebuild manifest.json)\n");
    exit(2);
}
if (!is_dir($target)) {
    mkdir($target, 0775, true);
}

foreach ($source === '' ? [] : glob("{$source}/*.{png,jpg,jpeg}", GLOB_BRACE) as $file) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $name = basename($file, ".{$extension}");
    $number = 1;
    $prefix = $name;
    if (preg_match($byLevel ? '/^(.*?)_(\d+)$/' : '/^(.*?)_?(\d)$/', $name, $m) && isset(NAMES[$m[1]])) {
        [$prefix, $number] = [$m[1], (int) $m[2]];
    }
    $type = NAMES[$prefix] ?? null;
    if ($type === null) {
        echo "skipped (unknown name): {$name}.{$extension}\n";
        continue;
    }
    if ($extension === 'png') {
        $image = imagecreatefrompng($file);
        imagepalettetotruecolor($image);
    } else {
        $image = cutout(imagecreatefromjpeg($file)); // flat background: cut the building out
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $out = $byLevel ? "{$type}-L{$number}.webp" : "{$type}-{$number}.webp";
    imagewebp($image, "{$target}/{$out}", WEBP_QUALITY);
    echo "{$name}.{$extension} -> {$out}\n";
}

// Manifest of everything in the target folder.
$manifest = [];
foreach (glob("{$target}/*-{[0-9],L[0-9]*}.webp", GLOB_BRACE) as $file) {
    preg_match('/^(.+)-(\d|L\d+)$/', basename($file, '.webp'), $m);
    $metrics = json_decode(shell_exec('php ' . escapeshellarg(__DIR__ . '/sprite-metrics.php') . ' ' . escapeshellarg($file)), true);
    $manifest[$m[1]][$m[2]] = ['file' => basename($file)] + $metrics[basename($file)];
}
ksort($manifest);
file_put_contents("{$target}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo count($manifest) . " types in manifest.json\n";
