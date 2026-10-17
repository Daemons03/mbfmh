<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

final class ViewTest extends EngineTestCase
{
    public function testViewOfStartingFarm(): void
    {
        $view = $this->decode($this->engine->view($this->state, self::NOW));

        self::assertSame(['capacity' => 200, 'full' => []], $view['barn']);
        self::assertSame([
            'side' => 12,
            'block_size' => 4,
            'owned_blocks' => [[0, 0], [1, 0], [1, 1], [0, 1], [2, 0], [2, 1]],
            'next_block' => [2, 2],
        ], $view['grid']);
        self::assertSame($this->data->xpForLevel(1), $view['xp_needed']);
        self::assertSame([], $view['jobs']);
        self::assertStringContainsString('"jobs":{}', json_encode($this->engine->view($this->state, self::NOW)));
    }

    public function testNoNextBlockWhenAllExpansionsBought(): void
    {
        $this->state['expansions'] = 55;

        $view = $this->engine->view($this->state, self::NOW);

        self::assertNull($view['grid']['next_block']);
        self::assertCount(55, $view['grid']['owned_blocks']);
    }

    public function testViewGivesJobReadyTimeWithSpeed(): void
    {
        $this->state['settings']['speed'] = 2;
        $this->state['barn']['items'] = ['corn' => 15, 'wildflower' => 200];
        $this->act('plant', ['id' => 'b2', 'crop' => 'corn']);

        $before = $this->decode($this->engine->view($this->state, self::NOW + 149));
        $after = $this->decode($this->engine->view($this->state, self::NOW + 150));

        self::assertSame(['ready_at' => self::NOW + 150, 'ready' => false], $before['jobs']['b2']);
        self::assertTrue($after['jobs']['b2']['ready']);
        self::assertSame(['wildflower'], $after['barn']['full']);
    }
}
