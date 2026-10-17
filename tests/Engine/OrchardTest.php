<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\GameData;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V2.2 orchards: production without ingredients, fruit + leaves (+ nectar from level 7).
 */
final class OrchardTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 250;
        $this->state['cash'] = 100_000_000;
        $this->state['buildings'][0]['level'] = 25; // 1 400 places per product
    }

    /**
     * @return iterable<string, array{string, string, int, int, int}>
     */
    public static function orchards(): iterable
    {
        // type, fruit, quantity, leaves, levels kept
        yield 'apple' => ['apple_orchard', 'apple', 325, 8, 18];
        yield 'cherry' => ['cherry_orchard', 'cherry', 220, 12, 14];
        yield 'almond' => ['almond_orchard', 'almond', 270, 10, 14];
        yield 'peach' => ['peach_orchard', 'peach', 200, 2, 14];
    }

    #[DataProvider('orchards')]
    public function testGrowsWithoutIngredients(string $type, string $fruit, int $quantity, int $leaves, int $levels): void
    {
        self::assertSame($levels, $this->data->maxBuildingLevel($type), 'levels with unknown cost are left out');
        $this->act('build', ['type' => $type, 'x' => 6, 'y' => 4]);

        $this->act('start_production', ['id' => 'b4']);
        $duration = $this->data->building($type)['production']['duration'];
        $this->assertGameError('not_ready', 'collect', ['id' => 'b4'], $duration - 1);
        $this->act('collect', ['id' => 'b4'], $duration);

        self::assertSame([$fruit => $quantity, 'leaves' => $leaves], $this->state['barn']['items']);
    }

    public function testNectarFromLevel7(): void
    {
        $this->act('build', ['type' => 'apple_orchard', 'x' => 6, 'y' => 4]);
        $this->state['buildings'][3]['level'] = 7;

        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 7200);

        self::assertSame(['apple' => 575, 'leaves' => 14, 'nectar' => 10], $this->state['barn']['items']);
    }

    public function testStartingBarnIsTooSmallForApples(): void
    {
        $this->state['buildings'][0]['level'] = 1;
        $this->act('build', ['type' => 'apple_orchard', 'x' => 6, 'y' => 4]);

        $error = $this->assertGameError('barn_too_small', 'start_production', ['id' => 'b4']);
        self::assertSame(['item' => 'apple', 'needed' => 325, 'capacity' => 200], $error->details);
    }

    public function testValidatorAcceptsEmptyInputsOnly(): void
    {
        $raw = $this->data->toArray();
        $raw['buildings']['apple_orchard']['production']['inputs_per_main_unit'] = [1, 2];

        self::assertStringContainsString('apple_orchard.production.inputs_per_main_unit', implode("\n", GameData::validate($raw)));
    }
}
