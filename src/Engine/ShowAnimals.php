<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use FermeTranquille\GameData;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Show animals (animaux de concours). State:
 *   show = { seed, opened, token_day, last_draw,
 *            animals: {animal: {level, cards}},
 *            active: {species: {animal, started_at, duration, outputs}} }
 *
 * Chests draw cards (or blueprints) with a deterministic RNG; the first card
 * unlocks an animal, the next ones level it up (1-5). An owned animal makes
 * its species' product for free, one of 3 durations; the building level caps
 * how many animals work at once, one per species.
 */
final readonly class ShowAnimals
{
    public const int MAX_LEVEL = 5;

    public function __construct(private GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->data->toArray()['show_animals'];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(Farm $farm): array
    {
        return $farm->show() + ['opened' => 0, 'animals' => [], 'active' => []];
    }

    /**
     * +daily_tokens on the first visit of each day.
     */
    public function grantDailyTokens(Farm $farm, int $now): void
    {
        $show = $this->state($farm);
        $today = Cafe::day($now);
        if (($show['token_day'] ?? null) === $today) {
            return;
        }
        $show['token_day'] = $today;
        $show['seed'] ??= substr(hash('sha256', "show:{$now}"), 0, 16);
        $farm->setShow($show);
        $farm->gain(['tokens' => $this->config()['daily_tokens']]);
    }

    public function slots(Farm $farm): int
    {
        $building = $farm->buildingsOfType($this->config()['building'])[0] ?? null;

        return $building === null ? 0 : (int) ($this->data->buildingLevel($building['type'], $building['level'])['animals'] ?? 0);
    }

    /**
     * One chest draw: ['blueprints' => true] or ['animal' => id]. Deterministic
     * per farm seed and chest number.
     *
     * @param array<string, mixed> $show
     * @return array{blueprints?: true, animal?: string}
     */
    public function draw(array $show): array
    {
        $chest = $this->config()['chest'];
        $seed = ($show['seed'] ?? 'seed') . ':' . $show['opened'];
        $roll = (new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true))))->nextFloat();

        $weights = ['__blueprints' => $chest['blueprint_chance']];
        foreach ($this->config()['animals'] as $id => $animal) {
            $weights[$id] = $chest['card_chance'][$animal['rarity']];
        }
        $total = array_sum($weights);

        $cumulative = 0.0;
        foreach ($weights as $id => $weight) {
            $cumulative += $weight / $total;
            if ($roll < $cumulative) {
                return $id === '__blueprints' ? ['blueprints' => true] : ['animal' => $id];
            }
        }

        return ['animal' => array_key_last($weights)];
    }

    /**
     * Adds cards; the first one unlocks the animal at level 1, the others
     * level it up (card_levels[level - 1] cards per level). Extra cards at max
     * level are kept but unused.
     *
     * @param array<string, mixed> $show
     * @return array<string, mixed>
     */
    public function addCards(array $show, string $animalId, int $count): array
    {
        $animal = $show['animals'][$animalId] ?? null;
        if ($animal === null) {
            $animal = ['level' => 1, 'cards' => 0];
            $count--;
        }
        $animal['cards'] += $count;

        $needs = $this->config()['card_levels'];
        while ($animal['level'] < self::MAX_LEVEL && $animal['cards'] >= $needs[$animal['level'] - 1]) {
            $animal['cards'] -= $needs[$animal['level'] - 1];
            $animal['level']++;
        }
        $show['animals'][$animalId] = $animal;

        return $show;
    }

    /**
     * @return array<string, int> what one activation yields
     */
    public function outputs(string $animalId, int $level, int $durationIndex): array
    {
        $animal = $this->config()['animals'][$animalId];
        $species = $this->config()['species'][$animal['species']];

        return [$species['product'] => $species['quantities'][$animal['rarity']][$level - 1][$durationIndex]];
    }

    /**
     * @return array<string, mixed>
     */
    public function view(Farm $farm, Clock $clock): array
    {
        $show = $this->state($farm);
        $active = [];
        foreach ($show['active'] as $species => $job) {
            $active[$species] = ['ready_at' => $clock->readyAt($job), 'ready' => $clock->isReady($job)];
        }

        return [
            'slots' => $this->slots($farm),
            'active_count' => count($show['active']),
            'active' => (object) $active,
            'last_draw' => $show['last_draw'] ?? null,
        ];
    }
}
