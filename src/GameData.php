<?php

declare(strict_types=1);

namespace FermeTranquille;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only access to data/game-data.json.
 *
 * The decoded and validated JSON is cached as a PHP file (opcache-friendly)
 * and rebuilt whenever the JSON changes. Keys starting with "_" are metadata
 * (_estimated, _note...) and carry no game rules.
 */
final class GameData
{
    public const string DEFAULT_FILE = __DIR__ . '/../data/game-data.json';
    public const string DEFAULT_CACHE_DIR = __DIR__ . '/../var/cache';

    private const array BUILDING_KINDS = ['field', 'storage', 'production'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param string|null $cacheDir null disables the on-disk cache
     */
    public static function load(string $file = self::DEFAULT_FILE, ?string $cacheDir = self::DEFAULT_CACHE_DIR): self
    {
        $real = realpath($file);
        if ($real === false) {
            throw new RuntimeException("Game data file not found: {$file}");
        }

        return new self(self::readCached($real, $cacheDir));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        self::assertValid($data);

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function item(string $id): array
    {
        return $this->data['items'][$id] ?? throw new InvalidArgumentException("Unknown item: {$id}");
    }

    public function hasItem(string $id): bool
    {
        return isset($this->data['items'][$id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function crop(string $id): array
    {
        return $this->data['crops'][$id] ?? throw new InvalidArgumentException("Unknown crop: {$id}");
    }

    /**
     * @return array<string, mixed>
     */
    public function building(string $type): array
    {
        return $this->data['buildings'][$type] ?? throw new InvalidArgumentException("Unknown building: {$type}");
    }

    /**
     * @return array<string, mixed>|null null when the level does not exist
     */
    public function buildingLevel(string $type, int $level): ?array
    {
        return $this->building($type)['levels'][$level - 1] ?? null;
    }

    public function maxBuildingLevel(string $type): int
    {
        return count($this->building($type)['levels']);
    }

    /**
     * @return array<string, mixed>|null null when the expansion does not exist
     */
    public function expansion(int $number): ?array
    {
        return $this->data['land']['expansions'][$number - $this->initialExpansions() - 1] ?? null;
    }

    public function blockSize(): int
    {
        return $this->data['land']['block_size'];
    }

    public function initialExpansions(): int
    {
        return $this->data['land']['initial_expansions'];
    }

    public function maxLevel(): int
    {
        return $this->data['player']['max_level'];
    }

    /**
     * XP needed to go from $level to $level + 1 (0 at max level).
     */
    public function xpForLevel(int $level): int
    {
        if ($level < 1) {
            throw new InvalidArgumentException("Invalid level: {$level}");
        }
        if ($level >= $this->maxLevel()) {
            return 0;
        }
        $curve = $this->data['player']['xp_curve'];

        return (int) round($curve['base'] * $level ** $curve['exponent']);
    }

    /**
     * cash, gold or a material id.
     */
    public function isResource(string $id): bool
    {
        return isset($this->data['currencies'][$id]) || isset($this->data['materials'][$id]);
    }

    /**
     * @return list<string>
     */
    public function materialIds(): array
    {
        return array_keys($this->data['materials']);
    }

    /**
     * @return array<string, mixed>
     */
    public function initialState(): array
    {
        return self::withoutMeta($this->data['initial_state']);
    }

    /**
     * @return array<string, mixed>
     */
    public function market(): array
    {
        return self::withoutMeta($this->data['market']);
    }

    /**
     * Human-readable validation errors, empty when the data is consistent.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function validate(array $data): array
    {
        return (new GameDataValidator($data, self::BUILDING_KINDS))->errors();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertValid(array $data): void
    {
        $errors = self::validate($data);
        if ($errors !== []) {
            throw new RuntimeException("Invalid game data:\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readCached(string $file, ?string $cacheDir): array
    {
        $mtime = filemtime($file);
        $cacheFile = $cacheDir === null ? null : $cacheDir . '/game-data.' . md5($file) . '.php';

        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = require $cacheFile;
            if (is_array($cached) && ($cached['mtime'] ?? null) === $mtime) {
                return $cached['data'];
            }
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Game data must be a JSON object: {$file}");
        }
        self::assertValid($data);

        if ($cacheFile !== null) {
            self::writeCache($cacheFile, ['mtime' => $mtime, 'data' => $data]);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeCache(string $cacheFile, array $payload): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // cache is an optimisation only
        }

        $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $code = "<?php\n\n// Generated from game-data.json, do not edit.\n\nreturn " . var_export($payload, true) . ";\n";
        if (file_put_contents($tmp, $code) !== false) {
            rename($tmp, $cacheFile);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cacheFile, true);
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutMeta(array $values): array
    {
        return array_filter($values, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }
}
