<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\ShowAnimals;

/**
 * payload: { packs } — each pack: 10 show tokens for 1 000 gold.
 */
final class BuyTokens implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $pack = (new ShowAnimals($farm->data))->config()['token_pack'];
        $packs = $payload->int('packs', 1);

        $farm->pay(array_map(static fn (int $q): int => $q * $packs, $pack['cost']));
        $farm->gain(['tokens' => $pack['tokens'] * $packs]);
    }
}
