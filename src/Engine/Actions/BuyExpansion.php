<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { option } — buys the next expansion with costs[option]
 * (each expansion offers alternative ways to pay, e.g. gold or cash).
 */
final class BuyExpansion implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $option = $payload->int('option', 0);
        $expansion = $farm->data->expansion($farm->expansions() + 1);

        if ($expansion === null) {
            throw new GameError('max_expansions', 'Tout le terrain est déjà acheté.');
        }
        $cost = $expansion['costs'][$option]
            ?? throw new GameError('invalid_payload', 'Moyen de paiement invalide.', ['field' => 'option']);

        $farm->assertLevel($expansion['unlock_level']);
        $farm->pay($cost);
        $farm->addExpansion();
    }
}
