<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;

/**
 * Admin operations: free edits of the farm, outside game rules. Pure, like
 * the engine: (state, op, payload, now) -> new state.
 */
final readonly class Admin
{
    public const float MIN_SPEED = 0.1;
    public const float MAX_SPEED = 1000.0;

    private const array OPS = [
        'set_resources', 'set_level', 'set_speed', 'finish_all_jobs', 'unlock_all', 'reset_farm',
        'set_tournament_curve', 'finish_tournament_week',
    ];

    public const int MAX_CURVE_PERCENT = 200;

    public function __construct(
        private GameData $data,
        private Engine $engine,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws GameError
     */
    public function apply(array $state, string $op, array $payload, int $now): array
    {
        if (!in_array($op, self::OPS, true)) {
            throw new GameError('unknown_admin_op', 'Opération admin inconnue.', ['op' => $op]);
        }
        if ($op === 'reset_farm') {
            return $this->engine->resolve($this->engine->newState(), $now);
        }

        $payload = new Payload($payload);
        $state = $this->engine->normalize($state);
        $state = match ($op) {
            'set_resources' => $this->setResources($state, $payload),
            'set_level' => $this->setLevel($state, $payload),
            'set_speed' => $this->setSpeed($state, $payload),
            'finish_all_jobs' => $this->finishAllJobs($state, $now),
            'unlock_all' => $this->unlockAll($state),
            'set_tournament_curve' => $this->setTournamentCurve($state, $payload),
            'finish_tournament_week' => $this->finishTournamentWeek($state, $now),
        };

        // Same post-processing as any read: fill market slots if some are missing.
        return $this->engine->resolve($state, $now);
    }

    /**
     * @return list<string>
     */
    public static function ops(): array
    {
        return self::OPS;
    }

    /**
     * payload: { curve: { group: { objective: 1-6, percent: 0-200 } } } — how
     * the invented farms of the tournament score (groups: first, podium,
     * middle, relegated); saved in settings.tournament_curve.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function setTournamentCurve(array $state, Payload $payload): array
    {
        $groups = array_keys($this->data->toArray()['tournament']['groups']);
        $rows = [];
        foreach ($payload->object('curve') as $group => $row) {
            $objective = $row['objective'] ?? null;
            $percent = $row['percent'] ?? null;
            if (!in_array($group, $groups, true) || !is_int($objective) || $objective < 1 || $objective > 6
                || !is_int($percent) || $percent < 0 || $percent > self::MAX_CURVE_PERCENT) {
                throw new GameError('invalid_payload', 'Courbe du tournoi invalide.', ['field' => "curve.{$group}"]);
            }
            $rows[] = ['group' => $group, 'objective' => $objective, 'percent' => $percent];
        }
        $state['settings'] = (array) $state['settings'];
        $state['settings']['tournament_curve'] = $rows;

        return $state;
    }

    /**
     * Ends the tournament now (ranking, rewards, promotion), for testing.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finishTournamentWeek(array $state, int $now): array
    {
        $farm = new Farm($state, $this->data);
        $this->engine->tournament()->finishWeek($farm, $now);

        return $farm->toArray();
    }

    /**
     * payload: { cash?, gold?, materials?: {id: n}, barn?: {item: n} } — barn may exceed capacity.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function setResources(array $state, Payload $payload): array
    {
        $changed = false;
        foreach (['cash', 'gold'] as $currency) {
            if ($payload->has($currency)) {
                $state[$currency] = $payload->int($currency, 0);
                $changed = true;
            }
        }
        if ($payload->has('materials')) {
            foreach ($payload->quantities('materials') as $id => $quantity) {
                if (!in_array($id, $this->data->materialIds(), true)) {
                    throw new GameError('unknown_material', "Matériau inconnu : {$id}.", ['id' => $id]);
                }
                $state['materials'][$id] = $quantity;
            }
            $changed = true;
        }
        if ($payload->has('barn')) {
            $items = (array) $state['barn']['items'];
            foreach ($payload->quantities('barn') as $id => $quantity) {
                if (!$this->data->hasItem($id)) {
                    throw new GameError('unknown_item', "Produit inconnu : {$id}.", ['item' => $id]);
                }
                if ($quantity === 0) {
                    unset($items[$id]);
                } else {
                    $items[$id] = $quantity;
                }
            }
            $state['barn']['items'] = $items;
            $changed = true;
        }
        if (!$changed) {
            throw new GameError('invalid_payload', 'Aucune ressource à modifier.');
        }

        return $state;
    }

    /**
     * payload: { level, xp? } — xp is the progress inside that level.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function setLevel(array $state, Payload $payload): array
    {
        $level = $payload->int('level', 1);
        if ($level > $this->data->maxLevel()) {
            throw new GameError('invalid_payload', "Niveau maximum : {$this->data->maxLevel()}.", ['field' => 'level']);
        }
        $xp = $payload->has('xp') ? $payload->int('xp', 0) : 0;
        $needed = $this->data->xpForLevel($level);
        if ($needed > 0 && $xp >= $needed) {
            throw new GameError('invalid_payload', "L'XP doit rester sous {$needed} à ce niveau.", ['field' => 'xp']);
        }

        $state['level'] = $level;
        $state['xp'] = $needed > 0 ? $xp : 0;

        return $state;
    }

    /**
     * payload: { speed } — running jobs follow the new speed immediately.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function setSpeed(array $state, Payload $payload): array
    {
        $speed = $payload->number('speed', self::MIN_SPEED, self::MAX_SPEED);
        // Keep integers as integers in the JSON (1 rather than 1.0).
        $state['settings']['speed'] = floor($speed) === $speed ? (int) $speed : $speed;

        return $state;
    }

    /**
     * Every job and waiting contract becomes ready now.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finishAllJobs(array $state, int $now): array
    {
        $speed = (float) $state['settings']['speed'];
        $finish = static fn (array $timer): array => ['started_at' => $now - (int) ceil($timer['duration'] / $speed)] + $timer;

        foreach ($state['buildings'] as $index => $building) {
            if (isset($building['job'])) {
                $state['buildings'][$index]['job'] = $finish($building['job']);
            }
        }
        foreach ($state['contracts'] ?? [] as $index => $contract) {
            if (isset($contract['wait'])) {
                $state['contracts'][$index]['wait'] = $finish($contract['wait']);
            }
        }

        return $state;
    }

    /**
     * Max level and all land: every crop, building and expansion is available.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function unlockAll(array $state): array
    {
        $state['level'] = $this->data->maxLevel();
        $state['xp'] = 0;
        $state['expansions'] = $this->data->initialExpansions() + count($this->data->toArray()['land']['expansions']);

        return $state;
    }
}
