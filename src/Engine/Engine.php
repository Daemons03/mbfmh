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
     * @return list<string>
     */
    public static function actionTypes(): array
    {
        return array_keys(self::ACTIONS);
    }
}
