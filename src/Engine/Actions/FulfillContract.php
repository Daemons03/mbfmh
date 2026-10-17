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
 * payload: { id } — delivers the products, collects the reward plus the
 * market upgrades bonus; a new contract takes the slot immediately.
 */
final class FulfillContract implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        [$index, $contract] = $farm->contract($payload->string('id'));

        if (Market::isWaiting($contract, $clock)) {
            throw new GameError('contract_waiting', 'Ce contrat n\'est pas encore disponible.', [
                'remaining' => $clock->remaining($contract['wait']),
            ]);
        }

        $farm->assertBarnHas($contract['items']);
        foreach ($contract['items'] as $item => $quantity) {
            $farm->removeFromBarn($item, $quantity);
        }
        $reward = $contract['reward'];
        $bonus = (new MarketUpgrades($farm->data))->contractBonus($farm);
        $farm->gain(['cash' => $reward['cash'] + $bonus['cash']]);
        $farm->gain($reward['materials'] ?? []);
        $farm->gain($bonus['materials']);
        $farm->addXp($reward['xp'] + $bonus['xp']);

        $market = new Market($farm->data);
        $next = $market->generate($farm, $clock, waiting: false);
        if ($next !== null) {
            $farm->replaceContract($index, $next);
        }
    }
}
