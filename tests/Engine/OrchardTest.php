<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Production;
use FermeTranquille\GameData;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V2.2 orchards: a fixed amount of fertilizer per run (from the silo, made of
 * dung), fruit + leaves (+ nectar from level 7).
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
     * @return iterable<string, array{string, int, int, string, int, int, int}>
     */
    public static function orchards(): iterable
    {
        // type, fertilizer, hours, fruit, quantity, leaves, levels kept
        yield 'apple' => ['apple_orchard', 10, 3, 'apple', 325, 8, 18];
        yield 'cherry' => ['cherry_orchard', 30, 12, 'cherry', 220, 12, 14];
        yield 'almond' => ['almond_orchard', 20, 8, 'almond', 270, 10, 14];
        yield 'peach' => ['peach_orchard', 5, 1, 'peach', 200, 2, 14];
    }

    #[DataProvider('orchards')]
    public function testFertilizeThenCollect(string $type, int $fertilizer, int $hours, string $fruit, int $quantity, int $leaves, int $levels): void
    {
        self::assertSame($levels, $this->data->maxBuildingLevel($type), 'levels with unknown cost are left out');
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

    public function testFertilizerIsFixedPerRunWhateverTheLevel(): void
    {
        self::assertSame(['fertilizer' => 10], Production::recipe($this->data, 'apple_orchard', 1)['inputs']);
        self::assertSame(['fertilizer' => 10], Production::recipe($this->data, 'apple_orchard', 18)['inputs']);
    }

    public function testFixedAndPerUnitInputsAddUp(): void
    {
        $raw = $this->data->toArray();
        $raw['buildings']['apple_orchard']['production']['inputs_per_main_unit'] = ['fertilizer' => 0.1];

        // 325 apples x 0.1 = 32.5 -> 33, plus 10 per run
        self::assertSame(['fertilizer' => 43], Production::recipe(GameData::fromArray($raw), 'apple_orchard', 1)['inputs']);
    }

    public function testSiloTurnsDungIntoFertilizerForOrchards(): void
    {
        $this->act('build', ['type' => 'silo', 'x' => 6, 'y' => 4]);
        $this->act('build', ['type' => 'apple_orchard', 'x' => 8, 'y' => 4]);
        $this->state['barn']['items'] = ['dung' => 35];

        $this->act('start_production', ['id' => 'b4']);
        $this->act('collect', ['id' => 'b4'], 1800);
        self::assertSame(['dung' => 5, 'fertilizer' => 30], $this->state['barn']['items']);

        $this->act('start_production', ['id' => 'b5'], 1800);
        $this->act('collect', ['id' => 'b5'], 1800 + 3 * 3600);
        self::assertSame(['dung' => 5, 'fertilizer' => 20, 'apple' => 325, 'leaves' => 8], $this->state['barn']['items']);
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

    public function testStartingBarnIsTooSmallForApples(): void
    {
        $this->state['buildings'][0]['level'] = 1;
        $this->act('build', ['type' => 'apple_orchard', 'x' => 6, 'y' => 4]);
        $this->state['barn']['items'] = ['fertilizer' => 10];

        $error = $this->assertGameError('barn_too_small', 'start_production', ['id' => 'b4']);
        self::assertSame(['item' => 'apple', 'needed' => 325, 'capacity' => 200], $error->details);
    }

    public function testValidatorChecksInputs(): void
    {
        $raw = $this->data->toArray();
        $raw['buildings']['apple_orchard']['production']['inputs_per_main_unit'] = [1, 2];
        $raw['buildings']['cherry_orchard']['production']['inputs_per_run'] = ['unicorn' => 3];
        $raw['buildings']['peach_orchard']['production']['inputs_per_run'] = [];

        $errors = implode("\n", GameData::validate($raw));
        self::assertStringContainsString('apple_orchard.production.inputs_per_main_unit', $errors);
        self::assertStringContainsString('cherry_orchard.production.inputs_per_run: unknown item unicorn', $errors);
        self::assertStringContainsString('peach_orchard.production.inputs_per_run: must be a non-empty object', $errors);
    }
}
