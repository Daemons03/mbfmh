<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\Production;

/**
 * payload: same as start_production — starts the chosen building, then every
 * other idle building of the same kind (variants included: premium coop with
 * the coop) with the same recipe and boost while resources last. The number
 * of batches is capped by each building's own maximum.
 */
final class StartProductionAll implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $chosen = $farm->building($payload->string('id'));
        $base = self::base($farm, $chosen['type']);
        if (($farm->data->building($chosen['type'])['production']['mode'] ?? null) === 'instant') {
            throw new GameError('instant_production', 'Fabrication instantanée : rien à lancer en série.');
        }

        StartOnAllIdle::run(
            $farm,
            $payload,
            $clock,
            new StartProduction(),
            fn (array $building): bool => self::base($farm, $building['type']) === $base,
            function (array $building, Payload $one) use ($farm): Payload {
                if (!$one->has('quantity') || !$one->has('recipe')) {
                    return $one;
                }
                $max = Production::maxQuantity($farm->data, $building['type'], $building['level'], $one->string('recipe'));

                return $one->with('quantity', min($one->int('quantity', 1), $max));
            },
        );
    }

    private static function base(Farm $farm, string $type): string
    {
        return $farm->data->building($type)['variant_of'] ?? $type;
    }
}
