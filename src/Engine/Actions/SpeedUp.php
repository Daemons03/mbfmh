<?php

declare(strict_types=1);

namespace FermeTranquille\Engine\Actions;

use FermeTranquille\Engine\Clock;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\GameError;
use FermeTranquille\GameData;
use FermeTranquille\Engine\Payload;

/**
 * payload: { id } — makes the crop or production of a field or production
 * building ready now, for gold: speed_up.gold_per_minute per started minute
 * left (one minute at least).
 */
final class SpeedUp implements Action
{
    public function apply(Farm $farm, Payload $payload, Clock $clock): void
    {
        $building = self::runningJob($farm, $payload->string('id'), $clock);

        $farm->pay(['gold' => self::cost($farm->data, $clock->remaining($building['job']))]);
        $building['job']['duration'] = 0; // ready since it started, like an instant boost
        $farm->saveBuilding($building);
    }

    public static function cost(GameData $data, int $remaining): int
    {
        $perMinute = $data->toArray()['speed_up']['gold_per_minute'] ?? 10;

        return (int) ($perMinute * max(1, (int) ceil($remaining / 60)));
    }

    /**
     * The building, refused unless a field or production building has a job still running.
     *
     * @return array<string, mixed>
     */
    public static function runningJob(Farm $farm, string $id, Clock $clock): array
    {
        $building = $farm->building($id);
        $kind = $farm->data->building($building['type'])['kind'];

        if (!in_array($kind, ['field', 'production'], true) || !isset($building['job'])) {
            throw new GameError('no_job', 'Aucune production en cours ici.');
        }
        if ($clock->isReady($building['job'])) {
            throw new GameError('already_ready', 'Déjà prêt : récoltez plutôt.');
        }

        return $building;
    }
}
