<?php

declare(strict_types=1);

namespace FermeTranquille;

use Closure;
use FermeTranquille\Engine\Engine;
use PDO;
use PDOException;

/**
 * player_state persistence with optimistic locking, plus the action log.
 */
final class StateRepository
{
    public const int LOG_RETENTION_DAYS = 30;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param (Closure(): int)|null $now
     * @param float $purgeChance probability of purging old logs on each logged action
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Engine $engine,
        ?Closure $now = null,
        private readonly float $purgeChance = 0.01,
    ) {
        $this->now = $now ?? time(...);
    }

    /**
     * Current state, created from initial_state on first access.
     *
     * @return array{state: array<string, mixed>, version: int}
     */
    public function load(int $userId): array
    {
        $row = $this->fetch($userId);
        if ($row !== null) {
            return $row;
        }

        $state = $this->engine->resolve($this->engine->newState(), ($this->now)());
        try {
            $this->pdo->prepare('INSERT INTO player_state (user_id, state_json, version) VALUES (?, ?, 1)')
                ->execute([$userId, self::encode($state)]);
        } catch (PDOException $e) {
            // Another request created it first: use theirs.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return $this->fetch($userId) ?? throw $e;
        }

        return ['state' => $this->engine->normalize(self::decode(self::encode($state))), 'version' => 1];
    }

    /**
     * Every farm with its owner's login (ranking of the players).
     *
     * @return list<array{user_id: int, login: string, state: array<string, mixed>}>
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT u.id, u.login, s.state_json FROM users u JOIN player_state s ON s.user_id = u.id ORDER BY u.id')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => [
            'user_id' => (int) $row['id'], 'login' => $row['login'], 'state' => $this->engine->normalize(self::decode($row['state_json'])),
        ], $rows);
    }

    /**
     * Writes $state if nobody wrote since $expectedVersion was read.
     *
     * @param array<string, mixed> $state
     * @return int the new version
     * @throws StaleStateException
     */
    public function save(int $userId, array $state, int $expectedVersion): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE player_state SET state_json = ?, version = version + 1 WHERE user_id = ? AND version = ?',
        );
        $stmt->execute([self::encode($state), $userId, $expectedVersion]);

        if ($stmt->rowCount() !== 1) {
            throw new StaleStateException("State of user {$userId} is no longer at version {$expectedVersion}");
        }

        return $expectedVersion + 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function logAction(int $userId, string $type, array $payload): void
    {
        $this->pdo->prepare('INSERT INTO action_log (user_id, action_type, payload_json, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $type, self::encode($payload), self::datetime(($this->now)())]);

        if ($this->purgeChance > 0 && mt_rand() / mt_getrandmax() < $this->purgeChance) {
            $this->purgeLog();
        }
    }

    /**
     * @return int deleted rows
     */
    public function purgeLog(): int
    {
        $cutoff = ($this->now)() - self::LOG_RETENTION_DAYS * 86400;
        $stmt = $this->pdo->prepare('DELETE FROM action_log WHERE created_at < ?');
        $stmt->execute([self::datetime($cutoff)]);

        return $stmt->rowCount();
    }

    /**
     * @return array{state: array<string, mixed>, version: int}|null
     */
    private function fetch(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT state_json, version FROM player_state WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'state' => $this->engine->normalize(self::decode((string) $row['state_json'])),
            'version' => (int) $row['version'],
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function datetime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
