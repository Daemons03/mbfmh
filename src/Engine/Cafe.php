<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use DateTimeImmutable;
use DateTimeZone;
use FermeTranquille\GameData;

/**
 * Café-boutique rules. State: cafe = { day, points, rewarded, served: {recipe: n} }.
 *
 * - Serving a recipe is instant: products leave the barn, cash and
 *   satisfaction points come in.
 * - Stars (0-7) come from how many times a recipe was served; each star adds
 *   +1 % cash and x1.1 points (rounded up, compounding: 100, 110, 121, 134...).
 * - Each day (server time zone) a customer group visits: its favourite
 *   recipes pay x2. Points and milestone rewards reset every day.
 */
final readonly class Cafe
{
    public function __construct(private GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->data->toArray()['cafe'];
    }

    public static function day(int $now): string
    {
        return date('Y-m-d', $now);
    }

    /**
     * @return array{id: string, name: string, emoji: string, favourites: list<string>}
     */
    public function group(int $now): array
    {
        $groups = $this->config()['groups'];
        $dayNumber = intdiv((new DateTimeImmutable(self::day($now), new DateTimeZone('UTC')))->getTimestamp(), 86400);

        return $groups[$dayNumber % count($groups)];
    }

    /**
     * Today's state, reset when a new day starts (served counts are kept).
     *
     * @return array{day: string, points: int, rewarded: int, served: array<string, int>}
     */
    public function today(Farm $farm, int $now): array
    {
        $state = $farm->cafe();
        $today = self::day($now);
        if (($state['day'] ?? null) !== $today) {
            $state = ['day' => $today, 'points' => 0, 'rewarded' => 0, 'served' => (array) ($state['served'] ?? [])];
        }

        return $state;
    }

    public function stars(int $served): int
    {
        $stars = 0;
        foreach ($this->config()['star_thresholds'] as $threshold) {
            if ($served >= $threshold) {
                $stars++;
            }
        }

        return $stars;
    }

    /**
     * @return array{cash: int, points: int, favourite: bool}
     */
    public function reward(string $recipeId, int $stars, int $now): array
    {
        $config = $this->config();
        $recipe = $config['recipes'][$recipeId];

        $cash = (int) round($recipe['cash'] * (1 + $config['star_cash_bonus'] * $stars));
        $points = $recipe['points'];
        for ($i = 0; $i < $stars; $i++) {
            $points = (int) ceil(round($points * $config['star_points_factor'], 6));
        }

        $favourite = in_array($recipeId, $this->group($now)['favourites'], true);
        if ($favourite) {
            $cash *= $config['favourite_factor'];
            $points *= $config['favourite_factor'];
        }

        return ['cash' => $cash, 'points' => $points, 'favourite' => $favourite];
    }

    /**
     * Derived values for the front.
     *
     * @return array<string, mixed>|null null without a café
     */
    public function view(Farm $farm, int $now): ?array
    {
        $config = $this->config();
        $cafe = $farm->buildingsOfType($config['building'])[0] ?? null;
        if ($cafe === null) {
            return null;
        }
        $today = $this->today($farm, $now);

        $recipes = [];
        foreach ($config['recipes'] as $id => $recipe) {
            $served = $today['served'][$id] ?? 0;
            $stars = $this->stars($served);
            $recipes[$id] = [
                'stars' => $stars,
                'served' => $served,
                'next_star_at' => $config['star_thresholds'][$stars] ?? null,
                'available' => $cafe['level'] >= $recipe['cafe_level'],
            ] + $this->reward($id, $stars, $now);
        }

        return [
            'level' => $cafe['level'],
            'day' => $today['day'],
            'group' => $this->group($now),
            'points' => $today['points'],
            'rewarded' => $today['rewarded'],
            'recipes' => $recipes,
        ];
    }
}
