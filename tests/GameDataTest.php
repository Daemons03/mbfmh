<?php

declare(strict_types=1);

namespace FermeTranquille\Tests;

use FermeTranquille\GameData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GameDataTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ft-gamedata-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function testRealDataFileIsValid(): void
    {
        $data = $this->realData();

        self::assertSame([], GameData::validate($data));
    }

    public function testScrapedValuesAreKept(): void
    {
        $data = GameData::fromArray($this->realData());

        self::assertSame(43, $data->crop('corn')['plant_cost']['cash']);
        self::assertSame(5, $data->item('corn')['barn_price']);
        self::assertSame(7.5, $data->item('corn')['market_price']);
        self::assertSame(200, $data->buildingLevel('barn', 1)['capacity']);
        self::assertSame(['egg' => 21, 'dung' => 7], $data->buildingLevel('chicken_coop', 1)['recipes']['egg']['outputs']);
        self::assertSame([['gold' => 50], ['cash' => 400]], $data->expansion(7)['costs']);
    }

    public function testLookups(): void
    {
        $data = GameData::fromArray($this->realData());

        self::assertTrue($data->hasItem('egg'));
        self::assertFalse($data->hasItem('unicorn'));
        self::assertNull($data->buildingLevel('barn', 999));
        self::assertSame(25, $data->maxBuildingLevel('barn'));
        self::assertNull($data->expansion(6), 'initial expansions are not purchasable');
        self::assertSame(8, $data->expansion(8)['number']);
        self::assertTrue($data->isResource('cash'));
        self::assertTrue($data->isResource('nails'));
        self::assertFalse($data->isResource('corn'));
        self::assertContains('boards', $data->materialIds());
    }

    public function testUnknownIdsThrow(): void
    {
        $data = GameData::fromArray($this->realData());

        $this->expectException(InvalidArgumentException::class);
        $data->building('castle');
    }

    public function testXpCurve(): void
    {
        $data = GameData::fromArray($this->realData());

        self::assertSame(50, $data->xpForLevel(1));
        self::assertSame((int) round(50 * 10 ** 1.6), $data->xpForLevel(10));
        self::assertSame(0, $data->xpForLevel($data->maxLevel()));
    }

    public function testMetadataIsStrippedFromInitialStateAndMarket(): void
    {
        $data = GameData::fromArray($this->realData());

        self::assertArrayNotHasKey('_estimated', $data->initialState());
        self::assertArrayNotHasKey('_note', $data->market());
        self::assertSame(2000, $data->initialState()['cash']);
        self::assertSame(6, $data->market()['slots']);
    }

    public function testValidationReportsBrokenReferences(): void
    {
        $data = $this->realData();
        $data['crops']['corn']['plant_cost'] = ['diamonds' => 3];
        $data['buildings']['windmill']['levels'][0]['recipes']['pig_feed']['inputs'] = ['unicorn' => 1];
        $data['buildings']['barn']['levels'][2]['level'] = 7;
        $data['land']['expansions'][0]['unlock_level'] = 500;
        unset($data['crops']['wheat']);

        $errors = implode("\n", GameData::validate($data));

        self::assertStringContainsString('crops.corn.plant_cost: unknown resource diamonds', $errors);
        self::assertStringContainsString('windmill.levels[0].recipes.pig_feed.inputs: unknown item unicorn', $errors);
        self::assertStringContainsString('buildings.barn.levels[2].level: expected 3', $errors);
        self::assertStringContainsString('land.expansions[0].unlock_level', $errors);
        self::assertStringContainsString('items.wheat: crop item without crops.wheat', $errors);
    }

    public function testValidationReportsMissingSections(): void
    {
        self::assertContains('missing section: items', GameData::validate([]));
    }

    public function testFromArrayRejectsInvalidData(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid game data');

        GameData::fromArray(['items' => []]);
    }

    public function testLoadWritesCacheAndRebuildsWhenJsonChanges(): void
    {
        $file = $this->tmpDir . '/game-data.json';
        $cacheDir = $this->tmpDir . '/cache';
        $data = $this->realData();
        file_put_contents($file, json_encode($data));

        self::assertSame(43, GameData::load($file, $cacheDir)->crop('corn')['plant_cost']['cash']);
        $cacheFiles = glob($cacheDir . '/game-data.*.php');
        self::assertCount(1, $cacheFiles);

        // Cache is used as long as the JSON mtime matches.
        $cached = require $cacheFiles[0];
        self::assertSame(filemtime($file), $cached['mtime']);

        // A changed JSON (new mtime) invalidates the cache.
        $data['crops']['corn']['plant_cost']['cash'] = 99;
        file_put_contents($file, json_encode($data));
        touch($file, $cached['mtime'] + 10);
        clearstatcache();

        self::assertSame(99, GameData::load($file, $cacheDir)->crop('corn')['plant_cost']['cash']);
        self::assertSame($cached['mtime'] + 10, (require $cacheFiles[0])['mtime']);
    }

    public function testInvalidJsonEditFailsLoudly(): void
    {
        $file = $this->tmpDir . '/game-data.json';
        $data = $this->realData();
        $data['buildings']['field']['levels'][0]['cost'] = ['cash' => -5];
        file_put_contents($file, json_encode($data));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('buildings.field.levels[0].cost.cash');

        GameData::load($file, null);
    }

    public function testLoadRejectsMissingFile(): void
    {
        $this->expectException(RuntimeException::class);

        GameData::load($this->tmpDir . '/nope.json', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function realData(): array
    {
        return json_decode((string) file_get_contents(GameData::DEFAULT_FILE), true, 512, JSON_THROW_ON_ERROR);
    }
}
