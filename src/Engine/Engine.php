<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\Engine\Actions\Action;
use FermeTranquille\GameData;

/**
 * Pure game logic: (state, action, now) -> new state. No I/O.
 */
final class Engine
{
    /** @var array<string, class-string<Action>> */
    private const array ACTIONS = [
        'build' => Actions\Build::class,
        'move' => Actions\Move::class,
        'demolish' => Actions\Demolish::class,
        'plant' => Actions\Plant::class,
        'harvest' => Actions\Harvest::class,
        'sell_barn' => Actions\SellBarn::class,
        'upgrade' => Actions\Upgrade::class,
    ];

    public function __construct(private readonly GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function newState(): array
    {
        return Farm::create($this->data)->toArray();
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the new state; $state itself is never modified
     * @throws GameError
     */
    public function apply(array $state, string $type, array $payload, int $now): array
    {
        $actionClass = self::ACTIONS[$type]
            ?? throw new GameError('unknown_action', 'Action inconnue.', ['type' => $type]);

        $farm = new Farm($state, $this->data);
        (new $actionClass())->apply($farm, new Payload($payload), new Clock($now, $farm->speed()));

        return $farm->toArray();
    }

    /**
     * Restores JSON shapes lost by decoding as arrays (empty objects).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function normalize(array $state): array
    {
        return (new Farm($state, $this->data))->toArray();
    }

    /**
     * Derived values for the front, never stored: barn usage, visible grid,
     * XP to next level and when each job is ready.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function view(array $state, int $now): array
    {
        $farm = new Farm($state, $this->data);
        $clock = new Clock($now, $farm->speed());

        $jobs = [];
        foreach ($farm->buildings() as $building) {
            if (isset($building['job'])) {
                $jobs[$building['id']] = [
                    'ready_at' => $clock->readyAt($building['job']),
                    'ready' => $clock->isReady($building['job']),
                ];
            }
        }

        $expansions = $farm->expansions();
        $owned = [];
        for ($n = 1; $n <= $expansions; $n++) {
            $owned[] = Land::blockPosition($n);
        }

        return [
            'barn' => ['used' => $farm->barnUsed(), 'capacity' => $farm->barnCapacity()],
            'grid' => [
                'side' => (new Land($this->data->blockSize()))->gridSide($expansions),
                'block_size' => $this->data->blockSize(),
                'owned_blocks' => $owned,
                'next_block' => $this->data->expansion($expansions + 1) === null ? null : Land::blockPosition($expansions + 1),
            ],
            'xp_needed' => $this->data->xpForLevel($farm->level()),
            'jobs' => (object) $jobs,
        ];
    }

    /**
     * @return list<string>
     */
    public static function actionTypes(): array
    {
        return array_keys(self::ACTIONS);
    }
}
