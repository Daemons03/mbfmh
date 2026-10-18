<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\Production;

/**
 * payload: { id, sell_surplus? } — every output goes to the barn, or nothing does;
 * with sell_surplus, what does not fit is sold at barn price instead.
 */
final class Collect implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = $farm->building($payload->string('id'));
        $definition = $farm->data->building($building['type']);

        if ($definition['kind'] !== 'production' || !isset($building['job'])) {
            throw new GameError('nothing_to_collect', 'Rien à récupérer ici.');
        }
        $job = $building['job'];
        if (!$clock->isReady($job)) {
            throw new GameError('not_ready', 'Pas encore prêt.', ['remaining' => $clock->remaining($job)]);
        }

        if ($payload->has('sell_surplus') && $payload->bool('sell_surplus')) {
            $farm->addToBarnSellingSurplus($job['outputs']);
        } else {
            $farm->addToBarn($job['outputs']);
        }
        $farm->addXp($job['xp'] ?? self::legacyXp($farm, $building));

        unset($building['job']);
        $farm->saveBuilding($building);
    }

    /**
     * Jobs started before recipes stored their XP: use the current recipe's.
     *
     * @param array<string, mixed> $building
     */
    private static function legacyXp(Farm $farm, array $building): int
    {
        $recipes = Production::recipes($farm->data, $building['type'], $building['level']);

        return $recipes[$building['job']['product']]['xp'] ?? 0;
    }
}
