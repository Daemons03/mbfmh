<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\Admin;
use FermeTranquille\Engine\Farm;
use FermeTranquille\Engine\Tournament;

/**
 * Solo tournament: weekly league of 20 (player + 19 invented farms), points =
 * market XP of the week, promotion / relegation, rank rewards, bot curve.
 */
final class TournamentTest extends EngineTestCase
{
    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tournament = new Tournament($this->data);
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW));
    }

    private function farm(): Farm
    {
        return new Farm($this->state, $this->data);
    }

    /** Ends the week through the admin operation. */
    private function finishWeek(): void
    {
        $admin = new Admin($this->data, $this->engine);
        $this->state = $this->decode($admin->apply($this->state, 'finish_tournament_week', [], self::NOW));
    }

    public function testEveryFarmStartsInBronze(): void
    {
        $state = $this->state['tournament'];
        self::assertSame(0, $state['tier']);
        self::assertSame(0, $state['start_xp']);
        self::assertSame(Tournament::week(self::NOW)['id'], $state['week']);
        self::assertSame('Bronze 1★', $this->tournament->tierInfo(0)['name']);
        self::assertSame('Diamant 3★', $this->tournament->tierInfo(17)['name']);
    }

    public function testObjectivesGrowFromBronzeToDiamond(): void
    {
        self::assertSame([100, 240, 500, 900, 1400, 2000], $this->tournament->objectives(0));
        self::assertEqualsWithDelta(1_500_000, $this->tournament->objectives(15)[5], 200, 'diamond 1★');
    }

    public function testRankRewards(): void
    {
        self::assertSame(['cash' => 1500, 'keys' => 10, 'rose_seed' => 5], $this->tournament->rankRewards(0, 1));
        self::assertSame(['cash' => 1200, 'keys' => 5, 'rose_seed' => 2], $this->tournament->rankRewards(0, 3));
        self::assertSame(['cash' => 900], $this->tournament->rankRewards(0, 15));
        self::assertSame(['cash' => 600], $this->tournament->rankRewards(0, 16));
        // Diamond 3★: the first gets 180 keys, 120 mallets (from gold 1★) and 60 buckets (from sapphire 1★).
        self::assertSame(['cash' => 460000, 'keys' => 180, 'mallets' => 120, 'buckets' => 60, 'rose_seed' => 5], $this->tournament->rankRewards(17, 1));
        self::assertSame(['cash' => 312000, 'keys' => 65, 'mallets' => 35, 'buckets' => 5, 'rose_seed' => 2], $this->tournament->rankRewards(12, 2));
    }

    public function testNineteenFarmsFollowTheCurve(): void
    {
        $bots = $this->tournament->botScores($this->farm(), $this->state['tournament'], 1.0);
        $objectives = $this->tournament->objectives(0);

        self::assertCount(19, $bots);
        self::assertSame(['first' => 1, 'podium' => 2, 'middle' => 12, 'relegated' => 4], array_count_values(array_column($bots, 'group')));
        self::assertSame('first', $bots[0]['group']);
        self::assertGreaterThanOrEqual((int) floor($objectives[5] * 1.10), $bots[0]['points']);
        self::assertLessThanOrEqual((int) floor($objectives[5] * 1.15), $bots[0]['points']);
        foreach (array_filter($bots, static fn (array $b): bool => $b['group'] === 'middle') as $bot) {
            self::assertGreaterThanOrEqual((int) floor($objectives[2] * 1.35), $bot['points']);
            self::assertLessThanOrEqual((int) floor($objectives[2] * 1.40), $bot['points']);
        }
        self::assertCount(19, array_unique(array_column($bots, 'name')), 'invented names, all different');
    }

    public function testFirstPlaceIsPromotedAndPaid(): void
    {
        $this->state['market_xp'] = 2_400; // above the first farm (2 000 + 10-15 %)
        $cash = $this->state['cash'];

        $this->finishWeek();

        $state = $this->state['tournament'];
        self::assertSame(1, $state['tier'], 'bronze 2★');
        self::assertSame(['week' => $state['last']['week'], 'tier' => 0, 'new_tier' => 1, 'rank' => 1, 'points' => 2_400,
            'rewards' => ['cash' => 1500, 'keys' => 10, 'rose_seed' => 5]], $state['last']);
        self::assertSame($cash + 1500, $this->state['cash']);
        self::assertSame(10 + 5, $this->state['materials']['keys'], '1st place + objective 6');
        self::assertSame(5, $this->state['barn']['items']['rose_seed']);
        self::assertSame(2_400, $state['start_xp'], 'points start again from zero');
    }

    public function testLastFivePlacesGoDown(): void
    {
        $this->state['tournament']['tier'] = 3;
        $this->finishWeek(); // 0 points: last
        self::assertSame(20, $this->state['tournament']['last']['rank']);
        self::assertSame(2, $this->state['tournament']['tier']);

        $this->state['tournament']['tier'] = 0;
        $this->finishWeek();
        self::assertSame(0, $this->state['tournament']['tier'], 'no league below bronze 1★');
    }

    public function testMiddleOfTheTableStays(): void
    {
        $this->state['tournament']['tier'] = 2;
        $this->state['market_xp'] = $this->tournament->objectives(2)[3]; // objective 4: above the middle group, below the podium

        $this->finishWeek();

        self::assertSame(4, $this->state['tournament']['last']['rank']);
        self::assertSame(2, $this->state['tournament']['tier']);
    }

    public function testANewWeekClosesTheLastOne(): void
    {
        $this->state['market_xp'] = 3_000;
        $nextWeek = Tournament::week(self::NOW)['start'] + 7 * 86400 + 60;

        $this->state = $this->decode($this->engine->resolve($this->state, $nextWeek));

        self::assertSame(1, $this->state['tournament']['tier']);
        self::assertSame(Tournament::week($nextWeek)['id'], $this->state['tournament']['week']);
        $this->state = $this->decode($this->engine->resolve($this->state, $nextWeek + 3600));
        self::assertSame(1, $this->state['tournament']['round'], 'closed once');
    }

    public function testPointsAreTheMarketXpOfTheWeek(): void
    {
        $this->state['barn']['items'] = ['wildflower' => 200, 'corn' => 200];
        $contract = $this->state['contracts'][0];
        $this->act('fulfill_contract', ['id' => $contract['id']]);

        $view = $this->engine->view($this->state, self::NOW + 3600);
        self::assertSame($contract['reward']['market_xp'], $view['tournament']['points']);
        self::assertCount(20, $view['tournament']['ranking']);
        self::assertSame(1, count(array_filter($view['tournament']['ranking'], static fn (array $r): bool => $r['me'])));
        self::assertSame($view['tournament']['rank'], array_search(true, array_column($view['tournament']['ranking'], 'me'), true) + 1);
    }

    public function testFarmsScoreAsTheWeekGoesOn(): void
    {
        $atStart = $this->tournament->botScores($this->farm(), $this->state['tournament'], 0.0);
        $atEnd = $this->tournament->botScores($this->farm(), $this->state['tournament'], 1.0);

        self::assertSame(0, max(array_column($atStart, 'points')));
        self::assertGreaterThan(2_000, max(array_column($atEnd, 'points')));
    }

    public function testAdminCurve(): void
    {
        $admin = new Admin($this->data, $this->engine);
        $this->state = $this->decode($admin->apply($this->state, 'set_tournament_curve', ['curve' => [
            'first' => ['objective' => 4, 'percent' => 10],
        ]], self::NOW));

        $bots = $this->tournament->botScores($this->farm(), $this->state['tournament'], 1.0);
        $first = array_values(array_filter($bots, static fn (array $b): bool => $b['group'] === 'first'))[0];
        self::assertLessThanOrEqual((int) floor(900 * 1.10), $first['points'], 'objective 4 + 10 %');
        self::assertSame(['objective' => 5, 'percent' => 15], $this->tournament->curve($this->farm())['podium'], 'other groups keep the default');

        foreach ([['first' => ['objective' => 7, 'percent' => 10]], ['king' => ['objective' => 6, 'percent' => 10]], ['first' => ['objective' => 6, 'percent' => -5]]] as $curve) {
            try {
                $admin->apply($this->state, 'set_tournament_curve', ['curve' => $curve], self::NOW);
                self::fail('invalid curve accepted');
            } catch (\FermeTranquille\Engine\GameError $e) {
                self::assertSame('invalid_payload', $e->errorCode);
            }
        }
    }

    public function testObjectivesPayAsSoonAsReached(): void
    {
        // Bronze 1★, first tournament: 100 / 240 / 500 / 900 / 1 400 / 2 000 points.
        $this->state['barn']['items'] = ['tulip_seed' => 1];
        $this->setBuilding('b2', ['level' => 1]);
        $this->act('plant', ['id' => 'b2', 'crop' => 'tulip']); // +450 market XP: objectives 1 and 2

        self::assertSame(2, $this->state['tournament']['claimed']);
        self::assertSame(['instant_feed' => 1, 'tulip_seed' => 1], $this->state['barn']['items'], 'the seed used, a new one won');

        $this->state['market_xp'] = 2_000; // e.g. the admin: paid at the next read
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + 60));
        self::assertSame(6, $this->state['tournament']['claimed']);
        self::assertSame(5, $this->state['materials']['keys']);
        self::assertSame(['instant_feed' => 1, 'tulip_seed' => 1, 'premium_feed' => 10, 'premium_humus' => 5, 'premium_fertilizer' => 10],
            $this->state['barn']['items']);
    }

    public function testObjectiveRewardsChangeEachWeekAndGrowWithTheTier(): void
    {
        $state = $this->state['tournament'];
        self::assertSame(['instant_feed' => 1], $this->tournament->objectiveReward($this->farm(), $state, 0));
        self::assertSame(['instant_fertilizer' => 1], $this->tournament->objectiveReward($this->farm(), ['round' => 1] + $state, 0));
        self::assertSame(['premium_seed_corn' => 2], $this->tournament->objectiveReward($this->farm(), ['round' => 2] + $state, 1), 'best crop unlocked at level 1');

        // Diamond 3★: the amounts of the game screen.
        $diamond = ['tier' => 17] + $state;
        $amounts = array_map(fn (int $n): int => current($this->tournament->objectiveReward($this->farm(), $diamond, $n)), range(0, 5));
        self::assertSame([5, 10, 150, 70, 150, 60], $amounts);
    }

    public function testANewWeekStartsWithNoObjectiveClaimed(): void
    {
        $this->state['market_xp'] = 500;
        $this->state = $this->decode($this->engine->resolve($this->state, self::NOW + 60));
        self::assertSame(3, $this->state['tournament']['claimed']);

        $this->finishWeek();

        self::assertSame(0, $this->state['tournament']['claimed']);
    }
}
