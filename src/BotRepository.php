<?php

declare(strict_types=1);

namespace FermeTranquille;

use FermeTranquille\Engine\BotWorld;
use PDO;
use PDOException;

/**
 * The bot world in the database: bot_world (one row: day, week, speed) and
 * bots (one row per bot, tier = its tier this week). The world is created on
 * first use and brought up to date on the first request of each day; a
 * version claim keeps two requests from simulating the same day twice.
 */
final class BotRepository
{
    private ?array $metaCache = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly BotWorld $world,
    ) {
    }

    /**
     * Creates the world if needed, then simulates the days it is behind
     * (at most max_catch_up_days per call).
     */
    public function ensure(int $now): void
    {
        $row = $this->metaRow();
        if ($row === null) {
            $this->create($now, 1.0);

            return;
        }
        if ($this->world->daysBehind($row['meta'], $now) === 0) {
            return;
        }
        $this->advance($row, $now, null);
    }

    /** Admin: simulates $days more days at once (tests, catching up). */
    public function advanceDays(int $now, int $days): void
    {
        $this->ensure($now);
        $row = $this->metaRow();
        if ($row !== null) {
            $this->advance($row, $now, max(1, min(60, $days)));
        }
    }

    /** Admin: a brand new world from level 1, keeping the speed. */
    public function reset(int $now): void
    {
        $speed = $this->meta()['speed'] ?? 1.0;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM bots');
            $this->pdo->exec('DELETE FROM bot_world');
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->metaCache = null;
        $this->create($now, (float) $speed);
    }

    /** Admin: speed of the bots (days of play per real day). */
    public function setSpeed(int $now, float $speed): void
    {
        $this->ensure($now);
        $row = $this->metaRow();
        $meta = ['speed' => $speed] + $row['meta'];
        $statement = $this->pdo->prepare('UPDATE bot_world SET meta_json = :meta, version = version + 1 WHERE id = 1');
        $statement->execute(['meta' => self::encode($meta)]);
        $this->metaCache = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function meta(): ?array
    {
        return $this->metaRow()['meta'] ?? null;
    }

    /**
     * Bots whose tier this week is $tier (null: all of them).
     *
     * @return list<array<string, mixed>>
     */
    public function bots(?int $tier = null): array
    {
        if ($tier === null) {
            $statement = $this->pdo->query('SELECT data_json FROM bots ORDER BY id');
        } else {
            $statement = $this->pdo->prepare('SELECT data_json FROM bots WHERE tier = :tier ORDER BY id');
            $statement->execute(['tier' => $tier]);
        }

        return array_map(static fn (array $row): array => self::decode($row['data_json']), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bot(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT data_json FROM bots WHERE id = :id');
        $statement->execute(['id' => $id]);
        $json = $statement->fetchColumn();

        return $json === false ? null : self::decode((string) $json);
    }

    // ------------------------------------------------------------ internals

    private function create(int $now, float $speed): void
    {
        $world = $this->world->create($now, substr(hash('sha256', "bots:{$now}:" . random_int(0, PHP_INT_MAX)), 0, 16), $speed);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO bot_world (id, meta_json, version) VALUES (1, :meta, 1)')
                ->execute(['meta' => self::encode($world['meta'])]);
            $this->writeBots($world['bots']);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack(); // another request created it first
            if ($this->metaRow(fresh: true) === null) {
                throw $e;
            }
        }
        $this->metaCache = null;
    }

    /**
     * @param array{meta: array<string, mixed>, version: int} $row
     */
    private function advance(array $row, int $now, ?int $days): void
    {
        $world = $this->world->advance($row['meta'], $this->bots(), $now, $days);
        $this->pdo->beginTransaction();
        try {
            $claim = $this->pdo->prepare('UPDATE bot_world SET meta_json = :meta, version = version + 1 WHERE id = 1 AND version = :version');
            $claim->execute(['meta' => self::encode($world['meta']), 'version' => $row['version']]);
            if ($claim->rowCount() === 0) {
                $this->pdo->rollBack(); // another request is simulating these days

                return;
            }
            $this->pdo->exec('DELETE FROM bots');
            $this->writeBots($world['bots']);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->metaCache = null;
    }

    /**
     * @param list<array<string, mixed>> $bots
     */
    private function writeBots(array $bots): void
    {
        foreach (array_chunk($bots, 100) as $chunk) {
            $rows = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $values = [];
            foreach ($chunk as $bot) {
                array_push($values, $bot['id'], $bot['tier'], self::encode($bot));
            }
            $this->pdo->prepare("INSERT INTO bots (id, tier, data_json) VALUES {$rows}")->execute($values);
        }
    }

    /**
     * @return array{meta: array<string, mixed>, version: int}|null
     */
    private function metaRow(bool $fresh = false): ?array
    {
        if ($this->metaCache !== null && !$fresh) {
            return $this->metaCache;
        }
        $row = $this->pdo->query('SELECT meta_json, version FROM bot_world WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

        return $this->metaCache = $row === false ? null : ['meta' => self::decode($row['meta_json']), 'version' => (int) $row['version']];
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
