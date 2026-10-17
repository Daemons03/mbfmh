<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Production;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Orchards (fertilizer per run, fruit + leaves, nectar from level 7), silo
 * (dung -> fertilizer) and composter (leaves -> humus): values from the game.
 */
final class OrchardTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200; // below max level, so XP is kept
        $this->state['cash'] = 100_000_000;
        $this->state['buildings'][0]['level'] = 25; // 1 400 places per product
    }

    /**
     * @return iterable<string, array{string, int, int, string, int, int, int}>
     */
    public static function orchards(): iterable
    {
        // type, fertilizer, hours, fruit, quantity, leaves, levels kept
        yield 'apple' => ['apple_orchard', 10, 3, 'apple', 325, 8, 18];
        yield 'cherry' => ['cherry_orchard', 30, 12, 'cherry', 220, 12, 18];
        yield 'almond' => ['almond_orchard', 20, 8, 'almond', 270, 10, 18];
        yield 'peach' => ['peach_orchard', 5, 1, 'peach', 200, 2, 18];
    }

    #[DataProvider('orchards')]
    public function testFertilizeThenCollect(string $type, int $fertilizer, int $hours, string $fruit, int $quantity, int $leaves, int $levels): void
    {
        self::assertSame($levels, $this->data->maxBuildingLevel($type), '18 levels (15-18 extrapolated for cherry, almond, peach)');
        $this->act('build', ['type' => $type, 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['fertilizer' => $fertilizer - 1];
        $error = $this->assertGameError('not_enough_items', 'start_production', ['id' => 'b4']);
        self::assertSame(['missing' => ['fertilizer' => 1]], $error->details);

        $this->state['barn']['items'] = ['fertilizer' => $fertilizer];
        $this->act('start_production', ['id' => 'b4']);
        self::assertSame([], $this->state['barn']['items']);
        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], $hours * 3600 - 1);
        $this->act('collect', ['id' => 'b4'], $hours * 3600);

        self::assertSame([$fruit => $quantity, 'leaves' => $leaves], $this->state['barn']['items']);
    }

    public function testFertilizerIsTheSameAtEveryLevel(): void
    {
        self::assertSame(['fertilizer' => 10], Production::recipe($this->data, 'apple_orchard', 1)['inputs']);
        self::assertSame(['fertilizer' => 10], Production::recipe($this->data, 'apple_orchard', 18)['inputs']);
    }

    public function testNectarFromLevel7(): void
    {
        $this->act('build', ['type' => 'apple_orchard', 'x' => 6, 'y' => 4]);
        $this->state['buildings'][3]['level'] = 7;
        $this->state['barn']['items'] = ['fertilizer' => 10];

        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 3 * 3600);

        self::assertSame(['apple' => 575, 'leaves' => 14, 'nectar' => 10], $this->state['barn']['items']);
    }

    public function testSiloBatches(): void
    {
        $this->act('build', ['type' => 'silo', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['dung' => 650];
        $cash = $this->state['cash'];

        $this->act('start_production', ['id' => 'b4', 'quantity' => 3]);
        self::assertSame(['dung' => 20], $this->state['barn']['items']);
        self::assertSame($cash - 3 * 209, $this->state['cash']);

        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], 3 * 3360 - 1);
        $this->act('collect', ['id' => 'b4'], 3 * 3360);
        self::assertSame(['dung' => 20, 'fertilizer' => 30], $this->state['barn']['items']);
        self::assertSame(3 * 600, $this->state['xp'], 'XP x3');
    }

    public function testHigherSiloLevelsAreFasterAndCheaper(): void
    {
        $level1 = Production::recipe($this->data, 'silo', 1);
        $level5 = Production::recipe($this->data, 'silo', 5);

        self::assertSame([210, 3360], [$level1['inputs']['dung'], $level1['duration']]);
        self::assertSame([196, 1100], [$level5['inputs']['dung'], $level5['duration']]);
        self::assertSame(10, Production::maxQuantity($this->data, 'silo', 2, 'fertilizer'), '100 fertilizer at level 2');
    }

    public function testComposterTurnsLeavesIntoHumus(): void
    {
        $this->act('build', ['type' => 'composter', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['leaves' => 40];

        $error = $this->assertGameError('quantity_too_high', 'start_production', ['id' => 'b4', 'quantity' => 3]);
        self::assertSame(['max' => 2], $error->details, 'level 1 holds 2 humus');
        $this->act('start_production', ['id' => 'b4', 'quantity' => 2]);
        $this->act('collect', ['id' => 'b4'], 2 * 4 * 3600);

        self::assertSame(['humus' => 2], $this->state['barn']['items']);
        self::assertSame(['leaves' => 9], Production::recipe($this->data, 'composter', 10)['inputs']);
        self::assertSame(300, Production::recipe($this->data, 'composter', 10)['duration']);
    }

    public function testStartingBarnIsTooSmallForApples(): void
    {
        $this->state['buildings'][0]['level'] = 1;
        $this->act('build', ['type' => 'apple_orchard', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['fertilizer' => 10];

        $error = $this->assertGameError('barn_too_small', 'start_production', ['id' => 'b4']);
        self::assertSame(['item' => 'apple', 'needed' => 325, 'capacity' => 200], $error->details);
    }
}
