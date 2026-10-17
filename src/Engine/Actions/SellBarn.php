<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;

/**
 * payload: { item, quantity } — sold at barn_price, total rounded down.
 */
final class SellBarn implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $item = $payload->string('item');
        $quantity = $payload->int('quantity', 1);

        if (!$farm->data->hasItem($item)) {
            throw new GameError('unknown_item', 'Produit inconnu.', ['item' => $item]);
        }

        $farm->removeFromBarn($item, $quantity);
        $farm->gain(['cash' => (int) floor($quantity * $farm->data->item($item)['barn_price'])]);
    }
}
