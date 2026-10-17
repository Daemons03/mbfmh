<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Production;

/**
 * Premium (gold), fertile (gold) and free-range (keys) variants, checked
 * against the player's screenshots of the original game.
 */
final class VariantTest extends EngineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->state['level'] = 200;
        $this->state['gold'] = 10_000_000;
        $this->state['cash'] = 100_000_000;
        $this->state['materials']['keys'] = 10_000;
        $this->setBuilding('b1', ['level' => 25]); // 1 400 places per product
    }

    public function testPremiumOutputsMatchTheGame(): void
    {
        $out = fn (string $type, int $level): array => Production::recipes($this->data, $type, $level)[array_key_first(Production::recipes($this->data, $type, $level))]['outputs'];

        self::assertSame(['apple' => 520, 'leaves' => 10], $out('premium_apple_orchard', 1));
        self::assertSame(['apple' => 760, 'leaves' => 14], $out('premium_apple_orchard', 5));
        self::assertSame(['peach' => 464, 'leaves' => 8], $out('premium_peach_orchard', 5));
        self::assertSame(['pig' => 256, 'dung' => 88], $out('premium_pigsty', 5));
        self::assertSame(['milk' => 552, 'dung' => 224], $out('premium_cowshed', 5));
        self::assertSame(['egg' => 45, 'dung' => 15], $out('premium_chicken_coop', 5));
        self::assertSame(['goat_cheese' => 375, 'dung' => 119], $out('premium_goat_stable', 5));
        self::assertSame(['feather' => 424, 'dung' => 154], $out('premium_duck_coop', 5));
        self::assertSame(['vanilla' => 105], Production::recipe($this->data, 'premium_meadow', 5, 'vanilla')['outputs']);
        self::assertSame(['chicken_feed' => 16], Production::recipe($this->data, 'premium_windmill', 12, 'chicken_feed')['outputs']);
        self::assertSame(['flower_honey' => 80], Production::recipe($this->data, 'premium_beehive', 7, 'flower_honey')['outputs']);
    }

    public function testFreeRangeOutputsMatchTheGame(): void
    {
        self::assertSame(['egg' => 34, 'dung' => 10], Production::recipe($this->data, 'free_range_chicken_coop', 5)['outputs']);
        self::assertSame(['goat_cheese' => 281, 'dung' => 82], Production::recipe($this->data, 'free_range_goat_stable', 5)['outputs']);
        self::assertSame(['milk' => 414, 'dung' => 154], Production::recipe($this->data, 'free_range_cowshed', 5)['outputs']);
    }

    public function testRecipeInputsAreTheBaseOnes(): void
    {
        foreach (['premium_cowshed', 'free_range_cowshed'] as $type) {
            $recipe = Production::recipe($this->data, $type, 1);
            self::assertSame([['cattle_feed' => 10], ['cash' => 185], 25200, 300],
                [$recipe['inputs'], $recipe['cost'], $recipe['duration'], $recipe['xp']], $type);
        }
    }

    public function testGoldCostsSumToTheShopPrices(): void
    {
        // The shop sells level 6 for the sum of levels 1-6.
        $sum = fn (string $type): int => array_sum(array_map(
            fn (int $l): int => $this->data->buildingLevel($type, $l)['cost']['gold'],
            range(1, 6),
        ));

        self::assertSame(350_000, $sum('premium_cowshed'));
        self::assertSame(110_000, $sum('premium_peach_orchard'));
        self::assertSame(52_125, $sum('premium_house'));
    }

    public function testBuildPremiumWithGoldAndFreeRangeWithKeys(): void
    {
        $this->act('build', ['type' => 'premium_chicken_coop', 'x' => 6, 'y' => 4]);
        self::assertSame(10_000_000 - 600, $this->state['gold']);

        $this->act('build', ['type' => 'free_range_chicken_coop', 'x' => 8, 'y' => 4]);
        self::assertSame(10_000 - 20, $this->state['materials']['keys']);

        $this->state['barn']['items'] = ['chicken_feed' => 20];
        $this->act('start_production', ['id' => 'b4']);
        $this->act('start_production', ['id' => 'b5']);
        $this->act('collect', ['id' => 'b4'], 390);
        $this->act('collect', ['id' => 'b5'], 390);
        self::assertSame(['egg' => 34 + 26, 'dung' => 12 + 8], $this->state['barn']['items']);
    }

    public function testPremiumFieldGrowsMore(): void
    {
        $this->act('build', ['type' => 'premium_field', 'x' => 6, 'y' => 4]);
        $this->act('plant', ['id' => 'b4', 'crop' => 'wildflower']);

        self::assertSame(['wildflower' => 12], $this->building('b4')['job']['outputs'], '7 x 1.6 = 11.2, rounded up');
        $this->act('plant', ['id' => 'b2', 'crop' => 'wildflower']);
        self::assertSame(['wildflower' => 7], $this->building('b2')['job']['outputs']);
    }

    /**
     * Values given by the player for normal | fertile | premium fields, levels 1 and 18.
     */
    public function testFieldVariantsMatchTheGame(): void
    {
        $expected = [
            'corn' => [[10, 15, 16], [27, 41, 44]],
            'wheat' => [[40, 60, 64], [150, 225, 240]],
            'carrot' => [[34, 51, 55], [154, 231, 247]],
        ];
        foreach ($expected as $crop => [$level1, $level18]) {
            foreach ([1 => $level1, 18 => $level18] as $level => $values) {
                $actual = array_map(
                    fn (string $field): int => \FermeTranquille\Engine\Crop::harvest($this->data, $crop, $field, $level, false)[$crop],
                    ['field', 'fertile_field', 'premium_field'],
                );
                self::assertSame($values, $actual, "{$crop} level {$level}");
            }
        }
    }

    public function testPremiumHouseHousesMore(): void
    {
        self::assertSame(14, $this->data->buildingLevel('premium_house', 1)['workers']);
        self::assertSame(-35, $this->data->buildingLevel('premium_house', 1)['happiness']);
        self::assertSame(18, $this->data->maxBuildingLevel('premium_house'));
    }
}
