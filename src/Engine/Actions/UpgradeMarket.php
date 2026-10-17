<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Market;
use FermeTranquille\Engine\MarketUpgrades;
use FermeTranquille\Engine\Payload;

/**
 * payload: { track } — next level of a market upgrade. More contract slots
 * are filled right away.
 */
final class UpgradeMarket implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $upgrades = new MarketUpgrades($farm->data);
        $track = $payload->string('track');
        $levels = $upgrades->tracks()[$track]['levels']
            ?? throw new GameError('unknown_upgrade', 'Amélioration inconnue.', ['track' => $track]);

        $level = $upgrades->level($farm, $track);
        $next = $levels[$level] ?? throw new GameError('max_level', 'Niveau maximum atteint.');
        $farm->pay($next['cost']);

        $farm->setMarketUpgradeLevel($track, $level + 1);
        (new Market($farm->data))->resolve($farm, $clock);
    }
}
