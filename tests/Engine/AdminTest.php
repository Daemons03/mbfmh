<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Admin;
use FermeTranquille\Engine\GameError;

final class AdminTest extends EngineTestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new Admin($this->data, $this->engine);
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
    }

    public function testSetResources(): void
    {
        $this->op('set_resources', [
            'cash' => 1_000_000,
            'gold' => 0,
            'materials' => ['nails' => 50, 'boards' => 7],
            'barn' => ['corn' => 500, 'egg' => 3],
        ]);

        self::assertSame(1_000_000, $this->state['cash']);
        self::assertSame(0, $this->state['gold']);
        self::assertSame(50, $this->state['materials']['nails']);
        self::assertSame(7, $this->state['materials']['boards']);
        self::assertSame(['corn' => 500, 'egg' => 3], $this->state['barn']['items'], 'admin may exceed capacity');
    }

    public function testSetResourcesPartialAndRemoval(): void
    {
        $this->state['barn']['items'] = ['corn' => 10, 'wheat' => 4];

        $this->op('set_resources', ['barn' => ['corn' => 0]]);

        self::assertSame(['wheat' => 4], $this->state['barn']['items']);
        self::assertSame(2000, $this->state['cash'], 'untouched');
    }

    public function testSetResourcesValidation(): void
    {
        $this->assertOpError('invalid_payload', 'set_resources', []);
        $this->assertOpError('invalid_payload', 'set_resources', ['cash' => -1]);
        $this->assertOpError('invalid_payload', 'set_resources', ['cash' => '10']);
        $this->assertOpError('invalid_payload', 'set_resources', ['materials' => ['nails' => 1.5]]);
        $this->assertOpError('invalid_payload', 'set_resources', ['barn' => [3, 4]]);
        $this->assertOpError('unknown_material', 'set_resources', ['materials' => ['gems' => 1]]);
        $this->assertOpError('unknown_item', 'set_resources', ['barn' => ['gems' => 1]]);
    }

    public function testSetLevel(): void
    {
        $this->op('set_level', ['level' => 14, 'xp' => 20]);

        self::assertSame(14, $this->state['level']);
        self::assertSame(20, $this->state['xp']);

        $this->op('set_level', ['level' => 3]);
        self::assertSame(0, $this->state['xp']);
    }

    public function testSetLevelValidation(): void
    {
        $this->assertOpError('invalid_payload', 'set_level', ['level' => 0]);
        $this->assertOpError('invalid_payload', 'set_level', ['level' => $this->data->maxLevel() + 1]);
        $this->assertOpError('invalid_payload', 'set_level', ['level' => 1, 'xp' => $this->data->xpForLevel(1)]);
    }

    public function testSetSpeedAppliesToRunningJobs(): void
    {
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $this->op('set_speed', ['speed' => 10]);

        self::assertSame(10, $this->state['settings']['speed']);
        $this->act('harvest', ['id' => 'b2'], 12); // 120 s / 10
        self::assertSame(['corn' => 15], $this->state['barn']['items']);
    }

    public function testSetSpeedBounds(): void
    {
        $this->op('set_speed', ['speed' => 0.5]);
        self::assertSame(0.5, $this->state['settings']['speed']);

        $this->assertOpError('invalid_payload', 'set_speed', ['speed' => 0]);
        $this->assertOpError('invalid_payload', 'set_speed', ['speed' => 1001]);
        $this->assertOpError('invalid_payload', 'set_speed', ['speed' => 'fast']);
    }

    public function testFinishAllJobs(): void
    {
        $this->state['settings']['speed'] = 0.5;
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);
        $this->act('discard_contract', ['id' => $this->state['contracts'][0]['id']]);

        $this->op('finish_all_jobs', [], 5);

        $view = $this->decode($this->engine->view($this->state, self::NOW + 5));
        self::assertTrue($view['jobs']['b2']['ready']);
        self::assertTrue(array_values($view['contracts'])[0]['available']);
        $this->act('harvest', ['id' => 'b2'], 5);
    }

    public function testUnlockAll(): void
    {
        $this->op('unlock_all', []);

        self::assertSame($this->data->maxLevel(), $this->state['level']);
        self::assertSame(55, $this->state['expansions']);
        $this->act('plant', ['id' => 'b2', 'crop' => 'barley']);
        // Block 55 is (7,5): the last cells of the 8th column of blocks.
        $this->act('build', ['type' => 'field', 'x' => 31, 'y' => 23]);
        $this->assertGameError('cell_locked', 'build', ['type' => 'field', 'x' => 31, 'y' => 24]);
    }

    public function testResetFarm(): void
    {
        $this->state['cash'] = 5;
        $this->state['level'] = 40;
        $this->state['buildings'] = [];

        $this->op('reset_farm', []);

        self::assertSame(2000, $this->state['cash']);
        self::assertSame(1, $this->state['level']);
        self::assertCount(3, $this->state['buildings']);
        self::assertCount(6, $this->state['contracts']);
    }

    public function testUnknownOp(): void
    {
        $this->assertOpError('unknown_admin_op', 'make_rich', []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function op(string $op, array $payload, int $elapsed = 0): void
    {
        $this->state = $this->decode($this->admin->apply($this->state, $op, $payload, self::NOW + $elapsed));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertOpError(string $code, string $op, array $payload): void
    {
        try {
            $this->admin->apply($this->state, $op, $payload, self::NOW);
        } catch (GameError $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());

            return;
        }
        self::fail("Expected GameError {$code} for {$op}");
    }
}
