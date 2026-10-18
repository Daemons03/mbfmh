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
        'rotate' => Actions\Rotate::class,
        'demolish' => Actions\Demolish::class,
        'plant' => Actions\Plant::class,
        'harvest' => Actions\Harvest::class,
        'sell_barn' => Actions\SellBarn::class,
        'upgrade' => Actions\Upgrade::class,
        'fulfill_contract' => Actions\FulfillContract::class,
        'discard_contract' => Actions\DiscardContract::class,
        'start_production' => Actions\StartProduction::class,
        'collect' => Actions\Collect::class,
        'collect_all' => Actions\CollectAll::class,
        'buy_expansion' => Actions\BuyExpansion::class,
        'serve_cafe' => Actions\ServeCafe::class,
        'open_chest' => Actions\OpenChest::class,
        'buy_tokens' => Actions\BuyTokens::class,
        'start_show_animal' => Actions\StartShowAnimal::class,
        'collect_show_animal' => Actions\CollectShowAnimal::class,
        'upgrade_library' => Actions\UpgradeLibrary::class,
        'upgrade_market' => Actions\UpgradeMarket::class,
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
        $clock = new Clock($now, $farm->speed());
        $this->settle($farm, $clock);
        (new $actionClass())->apply($farm, new Payload($payload), $clock);

        return $farm->toArray();
    }

    /**
     * Brings a stored state up to date without any player action (fills
     * market slots). Returns the state unchanged when nothing was needed.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function resolve(array $state, int $now): array
    {
        $farm = new Farm($state, $this->data);
        $this->settle($farm, new Clock($now, $farm->speed()));

        return $farm->toArray();
    }

    /**
     * What time alone changes, applied before reads and actions: starter
     * buildings, market slots, café daily reset, daily show tokens.
     */
    private function settle(Farm $farm, Clock $clock): void
    {
        $farm->ensureStarterBuildings();
        (new Market($this->data))->resolve($farm, $clock);
        if ($farm->cafe() !== []) {
            // Daily reset is persisted, so the state always shows today's gauge.
            $farm->setCafe((new Cafe($this->data))->today($farm, $clock->now));
        }
        if (isset($this->data->toArray()['show_animals'])) {
            (new ShowAnimals($this->data))->grantDailyTokens($farm, $clock->now);
        }
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

        $library = new Library($this->data);
        $jobs = [];
        $production = [];
        foreach ($farm->buildings() as $building) {
            if ($this->data->building($building['type'])['kind'] === 'production') {
                $recipes = [];
                foreach (Production::recipes($this->data, $building['type'], $building['level']) as $id => $recipe) {
                    $recipe['outputs'][$id] += $library->bonus($farm, $id); // as StartProduction does
                    $recipes[$id] = $recipe + [
                        'max_quantity' => Production::maxQuantity($this->data, $building['type'], $building['level'], $id),
                    ];
                }
                $production[$building['id']] = [
                    'mode' => $this->data->building($building['type'])['production']['mode'] ?? 'run',
                    'recipes' => $recipes,
                ];
            }
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

        $contracts = [];
        foreach ($farm->contracts() as $contract) {
            if (isset($contract['wait'])) {
                $contracts[$contract['id']] = [
                    'available_at' => $clock->readyAt($contract['wait']),
                    'available' => $clock->isReady($contract['wait']),
                ];
            }
        }

        return [
            'barn' => ['capacity' => $farm->barnCapacity(), 'full' => $farm->barnFullItems()],
            'grid' => [
                'side' => (new Land($this->data->blockSize()))->gridSide($expansions),
                'block_size' => $this->data->blockSize(),
                'owned_blocks' => $owned,
                'next_block' => $this->data->expansion($expansions + 1) === null ? null : Land::blockPosition($expansions + 1),
            ],
            'xp_needed' => $this->data->xpForLevel($farm->level()),
            'workers' => $farm->workers(),
            'happiness' => [
                'value' => $farm->happiness(),
                'cost_factor' => round($this->data->costFactor($farm->happiness()), 4),
            ],
            'jobs' => (object) $jobs,
            'contracts' => (object) $contracts,
            'production' => (object) $production,
            'cafe' => (new Cafe($this->data))->view($farm, $now),
            'show' => isset($this->data->toArray()['show_animals']) ? (new ShowAnimals($this->data))->view($farm, $clock) : null,
            'library' => $library->view($farm),
            'market' => (new MarketUpgrades($this->data))->view($farm),
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
