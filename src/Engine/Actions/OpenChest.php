<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\Engine\Payload;
use FermeTranquille\Engine\ShowAnimals;

/**
 * payload: { count: 1 | 11 } — 10 tokens for one chest, 100 for eleven; the
 * eleven chests all give the same draw.
 */
final class OpenChest implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $rules = new ShowAnimals($farm->data);
        $chest = $rules->config()['chest'];
        $count = $payload->int('count', 1);
        $cost = match ($count) {
            1 => $chest['cost'],
            $chest['bulk_count'] => $chest['bulk_cost'],
            default => throw new GameError('invalid_payload', "On ouvre 1 ou {$chest['bulk_count']} coffres.", ['field' => 'count']),
        };

        $farm->pay(['tokens' => $cost]);
        $show = $rules->state($farm);
        $show['seed'] ??= substr(hash('sha256', "show:{$clock->now}"), 0, 16);
        $draw = $rules->draw($show);
        $show['opened']++;

        if (isset($draw['blueprints'])) {
            $farm->gain(['blueprints' => $count]);
            $show['last_draw'] = ['blueprints' => $count];
        } else {
            $before = $show['animals'][$draw['animal']] ?? null;
            $show = $rules->addCards($show, $draw['animal'], $count);
            $show['last_draw'] = ['animal' => $draw['animal'], 'count' => $count, 'new' => $before === null];
        }
        $farm->setShow($show);
    }
}
