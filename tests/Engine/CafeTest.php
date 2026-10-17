<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Cafe;

/**
 * Café-boutique: instant recipes, stars, customer group of the day (x2),
 * daily satisfaction gauge with milestone rewards.
 */
final class CafeTest extends EngineTestCase
{
    private Cafe $cafe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cafe = new Cafe($this->data);
        $this->state['level'] = 40;
        $this->state['barn']['items'] = ['egg' => 1000, 'butter' => 1000, 'vanilla_extract' => 1000];
    }

    public function testNeedsTheCafe(): void
    {
        $this->assertGameError('no_cafe', 'serve_cafe', ['recipe' => 'scrambled_eggs']);
    }

    public function testCafeLevelUnlocksRecipes(): void
    {
        $this->act('build', ['type' => 'cafe']);

        $error = $this->assertGameError('cafe_level_too_low', 'serve_cafe', ['recipe' => 'vanilla_cake']);
        self::assertSame(['required' => 3], $error->details);
        $this->assertGameError('unknown_recipe', 'serve_cafe', ['recipe' => 'pizza']);

        $this->setBuilding($this->cafeId(), ['level' => 3]);
        $this->act('serve_cafe', ['recipe' => 'vanilla_cake'], $this->plainDay('vanilla_cake'));
        self::assertSame(1000 - 40, $this->state['barn']['items']['egg']);
    }

    public function testServingPaysCashPointsAndXp(): void
    {
        $this->act('build', ['type' => 'cafe']);
        $at = $this->plainDay('scrambled_eggs');

        $this->act('serve_cafe', ['recipe' => 'scrambled_eggs'], $at);

        self::assertSame(['egg' => 960, 'butter' => 995, 'vanilla_extract' => 1000], $this->state['barn']['items']);
        self::assertSame(2000 + 3000, $this->state['cash']);
        self::assertSame(60, $this->state['cafe']['points']);
        self::assertSame(['scrambled_eggs' => 1], $this->state['cafe']['served']);
        self::assertSame(30, $this->state['xp'], '60 points x 0.5');
    }

    public function testStarRewardsMatchTheGame(): void
    {
        $at = self::NOW + $this->plainDay('vanilla_cake');
        $points = array_map(fn (int $s): int => $this->cafe->reward('vanilla_cake', $s, $at)['points'], range(0, 4));
        $cash = array_map(fn (int $s): int => $this->cafe->reward('vanilla_cake', $s, $at)['cash'], range(0, 2));

        self::assertSame([100, 110, 121, 134, 148], $points);
        self::assertSame([7000, 7070, 7140], $cash);
    }

    public function testStarsComeFromServing(): void
    {
        self::assertSame([0, 1, 1, 2, 7], array_map($this->cafe->stars(...), [2, 3, 17, 18, 1458]));
    }

    public function testFavouritesOfTheDayPayDouble(): void
    {
        $at = self::NOW + $this->favouriteDay('vanilla_cake');

        self::assertSame(['cash' => 14000, 'points' => 200, 'favourite' => true], $this->cafe->reward('vanilla_cake', 0, $at));
    }

    public function testGroupsRotateEveryDay(): void
    {
        $seen = [];
        for ($day = 0; $day < 7; $day++) {
            $seen[] = $this->cafe->group(self::NOW + $day * 86400)['id'];
        }

        self::assertCount(7, array_unique($seen));
        self::assertSame($seen[0], $this->cafe->group(self::NOW + 7 * 86400)['id']);
    }

    public function testMilestonesAreGrantedOnceAndResetDaily(): void
    {
        $this->act('build', ['type' => 'cafe']);
        $at = $this->plainDay('scrambled_eggs');
        $this->state['barn']['items']['egg'] = 10_000;

        // 60 points each: 4 serves = 240 >= 190 -> 90 000 cash once.
        for ($i = 0; $i < 4; $i++) {
            $this->act('serve_cafe', ['recipe' => 'scrambled_eggs'], $at);
        }
        $reward = $this->cafe->reward('scrambled_eggs', 1, self::NOW + $at)['cash'];
        self::assertSame(2000 + 3 * 3000 + $reward + 90_000, $this->state['cash']);
        self::assertSame(1, $this->state['cafe']['rewarded']);

        // Next day: the gauge starts over, stars stay.
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + $at + 86400));
        self::assertSame(0, $this->state['cafe']['points']);
        self::assertSame(0, $this->state['cafe']['rewarded']);
        self::assertSame(['scrambled_eggs' => 4], $this->state['cafe']['served']);
    }

    public function testKeysMilestone(): void
    {
        $this->act('build', ['type' => 'cafe']);
        $this->setBuilding($this->cafeId(), ['level' => 7]);
        $at = $this->plainDay('millefeuille');
        $this->state['barn']['items'] += ['cream' => 10_000];

        // millefeuille: 240 points (at least) -> 4 serves pass 760.
        for ($i = 0; $i < 4; $i++) {
            $this->act('serve_cafe', ['recipe' => 'millefeuille'], $at);
        }

        self::assertSame(9, $this->state['materials']['keys']);
        self::assertSame(2, $this->state['cafe']['rewarded']);
    }

    public function testWorksOnANormalizedState(): void
    {
        $this->act('build', ['type' => 'cafe']);
        $this->act('serve_cafe', ['recipe' => 'scrambled_eggs'], $this->plainDay('scrambled_eggs'));

        // As loaded from MySQL: normalize() turns empty-able maps into objects.
        $normalized = $this->engine->normalize($this->state);
        $view = $this->engine->view($normalized, self::NOW + $this->plainDay('scrambled_eggs'));
        $next = $this->engine->apply($normalized, 'serve_cafe', ['recipe' => 'scrambled_eggs'], self::NOW + $this->plainDay('scrambled_eggs'));

        self::assertSame(1, $view['cafe']['recipes']['scrambled_eggs']['served']);
        self::assertSame(2, ((array) $next['cafe']['served'])['scrambled_eggs']);
    }

    public function testViewDescribesTheCafe(): void
    {
        self::assertNull($this->decode($this->engine->view($this->state, self::NOW))['cafe']);

        $this->act('build', ['type' => 'cafe']);
        $view = $this->decode($this->engine->view($this->state, self::NOW))['cafe'];

        self::assertSame(1, $view['level']);
        self::assertCount(16, $view['recipes']);
        self::assertTrue($view['recipes']['scrambled_eggs']['available']);
        self::assertFalse($view['recipes']['millefeuille']['available']);
        self::assertSame(3, $view['recipes']['scrambled_eggs']['next_star_at']);
        self::assertSame($this->cafe->group(self::NOW)['id'], $view['group']['id']);
    }

    /**
     * Seconds after NOW to a day where $recipe is not a favourite.
     */
    private function plainDay(string $recipe): int
    {
        for ($day = 0; $day < 7; $day++) {
            if (!in_array($recipe, $this->cafe->group(self::NOW + $day * 86400)['favourites'], true)) {
                return $day * 86400;
            }
        }
        self::fail("{$recipe} is a favourite every day");
    }

    private function favouriteDay(string $recipe): int
    {
        for ($day = 0; $day < 7; $day++) {
            if (in_array($recipe, $this->cafe->group(self::NOW + $day * 86400)['favourites'], true)) {
                return $day * 86400;
            }
        }
        self::fail("{$recipe} is never a favourite");
    }

    private function cafeId(): string
    {
        foreach ($this->state['buildings'] as $building) {
            if ($building['type'] === 'cafe') {
                return $building['id'];
            }
        }
        self::fail('No café');
    }
}
