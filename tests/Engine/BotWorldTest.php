<?php

declare(strict_types=1);

namespace FermeTranquille\Tests\Engine;

use FermeTranquille\Engine\BotWorld;
use FermeTranquille\Engine\Engine;
use FermeTranquille\Engine\TournamentOpponents;
use FermeTranquille\GameData;
use PHPUnit\Framework\TestCase;

/**
 * Bot world with the real data (60 bots to stay quick): creation, days,
 * profiles, chains, weeks and leagues, and the tournament against bots.
 */
final class BotWorldTest extends TestCase
{
    private const int MONDAY = 1792360800; // 2026-10-19 00:00 Europe/Paris

    private GameData $data;
    private BotWorld $world;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Paris');
        $raw = GameData::load(cacheDir: null)->toArray();
        $raw['bots']['count'] = 60;
        $this->data = GameData::fromArray($raw);
        $this->world = new BotWorld($this->data);
    }

    public function testCreatesBotsAtLevelOneWithTheProfileShares(): void
    {
        $world = $this->world->create(self::MONDAY + 3600, 'seed');

        self::assertCount(60, $world['bots']);
        self::assertEquals(['90' => 12, '70' => 18, '60' => 24, '50' => 6], array_count_values(array_column($world['bots'], 'title')));
        self::assertCount(60, array_unique(array_column($world['bots'], 'name')), 'distinct names');
        foreach ($world['bots'] as $bot) {
            self::assertSame(0, $bot['tier']);
            self::assertContains(['farmhouse', 1], $bot['buildings']);
            self::assertSame(2, count(array_filter($bot['buildings'], static fn (array $b): bool => $b[0] === 'field')));
        }
        self::assertSame(self::MONDAY, $world['meta']['day_start']);
        self::assertSame('2026-W43', $world['meta']['week']);
        self::assertGreaterThan(0, $world['bots'][0]['market_xp'], 'today is already played');
    }

    public function testNamesGetANumberWhenTheyRunOut(): void
    {
        $raw = $this->data->toArray();
        $raw['bots']['names'] = ['Darius', 'Victor Hugo'];
        $raw['bots']['count'] = 5;
        $names = array_column((new BotWorld(GameData::fromArray($raw)))->create(self::MONDAY, 's')['bots'], 'name');

        sort($names);
        self::assertCount(5, array_unique($names));
        self::assertContains('Darius III', array_map(static fn (string $n): string => str_replace('Victor Hugo', 'Darius', $n), $names));
    }

    public function testDeterministic(): void
    {
        $a = $this->world->create(self::MONDAY, 'same');
        $b = $this->world->create(self::MONDAY, 'same');

        $a = $this->world->advance($a['meta'], $a['bots'], self::MONDAY + 5 * 86400);
        $b = $this->world->advance($b['meta'], $b['bots'], self::MONDAY + 5 * 86400);
        self::assertSame($a, $b);
    }

    public function testBusierProfilesGrowFasterAndCashNeverGoesNegative(): void
    {
        $world = $this->world->create(self::MONDAY, 'growth');
        for ($day = 1; $day <= 20; $day++) {
            $world = $this->world->advance($world['meta'], $world['bots'], self::MONDAY + $day * 86400 + 3600);
            foreach ($world['bots'] as $bot) {
                self::assertGreaterThanOrEqual(0, $bot['cash'], "{$bot['name']} day {$day}");
            }
        }
        $average = static function (array $bots, string $title): float {
            $mine = array_filter($bots, static fn (array $b): bool => $b['title'] === $title);
            return array_sum(array_column($mine, 'market_xp')) / count($mine);
        };

        self::assertGreaterThan($average($world['bots'], '50') * 1.2, $average($world['bots'], '90'));
        self::assertGreaterThan(10, min(array_column($world['bots'], 'level')));
        $types = array_unique(array_merge(...array_map(static fn (array $b): array => array_column($b['buildings'], 0), $world['bots'])));
        self::assertContains('chicken_coop', $types, 'they build animals');
        self::assertContains('windmill', $types);
    }

    public function testChainsNeedTheirInputs(): void
    {
        $bot = $this->world->create(self::MONDAY, 'chain')['bots'][0];
        $bot['level'] = 10;
        $bot['cash'] = 10_000_000;
        $bot['buildings'] = [['field', 1], ['field', 1]];
        $fieldsOnly = $this->world->produce($bot, 86400);

        $bot['buildings'][] = ['chicken_coop', 1];
        self::assertSame($fieldsOnly, $this->world->produce($bot, 86400), 'no windmill: no feed, the coop stays idle');

        $bot['buildings'][] = ['windmill', 1];
        self::assertNotSame($fieldsOnly, $this->world->produce($bot, 86400), 'corn -> feed -> eggs');
    }

    public function testShortOfCashTheFieldsStillPlant(): void
    {
        $bot = $this->world->create(self::MONDAY, 'cash')['bots'][0];
        $bot['cash'] = 50;

        $day = $this->world->produce($bot, 86400);
        self::assertLessThanOrEqual(50, $day['cost']);
        self::assertGreaterThan($day['cost'], $day['revenue']);
    }

    public function testFasterBotsMakeMore(): void
    {
        $slow = $this->world->create(self::MONDAY, 'speed', 1.0);
        $fast = $this->world->create(self::MONDAY, 'speed', 3.0);
        // The first days are short of cash either way: compare after a few.
        $slow = $this->world->advance($slow['meta'], $slow['bots'], self::MONDAY + 4 * 86400);
        $fast = $this->world->advance($fast['meta'], $fast['bots'], self::MONDAY + 4 * 86400);

        self::assertGreaterThan(
            array_sum(array_column($slow['bots'], 'market_xp')),
            array_sum(array_column($fast['bots'], 'market_xp')),
        );
    }

    public function testMondayClosesTheWeekWithPromotions(): void
    {
        $world = $this->world->create(self::MONDAY, 'week');
        // + 2 h: Sunday 25 October lasts 25 hours (clocks go back).
        $world = $this->world->advance($world['meta'], $world['bots'], self::MONDAY + 7 * 86400 + 2 * 3600);

        self::assertSame('2026-W44', $world['meta']['week']);
        $tiers = array_count_values(array_column($world['bots'], 'tier'));
        // 60 bots of tier 0: leagues of 19, 20, 20 (+ the player's seat) -> 3 promoted each, nobody below 0.
        ksort($tiers);
        self::assertSame([0 => 51, 1 => 9], $tiers);
        foreach ($world['bots'] as $bot) {
            self::assertSame(0, $bot['history']['2026-W43'][0]);
            self::assertSame($bot['day_start_mxp'], $bot['week_start_mxp'], 'the new week starts with Monday');
        }
    }

    public function testRelegationFromAHigherTier(): void
    {
        $bots = $this->world->create(self::MONDAY, 'down')['bots'];
        foreach ($bots as $i => $bot) {
            $bots[$i]['tier'] = 4;
            $bots[$i]['week_start_mxp'] = 0;
            $bots[$i]['market_xp'] = 1000 + $i;
        }

        // Leagues of 19, 20, 20 and 1: 3 + 3 + 3 promoted, 4 + 5 + 5 relegated (a quarter at most).
        $closed = $this->world->closeWeek($bots, '2026-W43');
        $tiers = array_count_values(array_column($closed, 'tier'));
        ksort($tiers);
        self::assertSame([3 => 14, 4 => 37, 5 => 9], $tiers);
    }

    public function testPlacesInTheirLeaguePayGold(): void
    {
        $bots = array_slice($this->world->create(self::MONDAY, 'gold')['bots'], 0, 19);
        foreach ($bots as $i => $bot) {
            $bots[$i]['gold'] = 0;
            $bots[$i]['week_start_mxp'] = 0;
            $bots[$i]['market_xp'] = 100 * ($i + 1); // the last one wins
        }

        $closed = $this->world->closeWeek($bots, '2026-W43');
        self::assertSame(1000, $closed[18]['gold'], '1st in bronze 1★');
        self::assertSame(500, $closed[17]['gold']);
        self::assertSame(250, $closed[10]['gold'], 'middle of the table');
        self::assertSame(100, $closed[0]['gold'], 'relegated');
    }

    public function testGoldBuysPremiumBuildings(): void
    {
        $bot = $this->world->create(self::MONDAY, 'premium')['bots'][0];
        $bot['level'] = 20;
        $bot['cash'] = 5_000_000;
        $bot['gold'] = 100_000;
        $bot['expansions'] = 20;
        $bot['buildings'][] = ['house', 1];
        $bot['buildings'][] = ['house', 1];

        $after = $this->world->simulateDay($bot, ['seed' => 's', 'speed' => 1.0], self::MONDAY);
        $types = array_column($after['buildings'], 0);
        self::assertNotEmpty(array_filter($types, static fn (string $t): bool => str_starts_with($t, 'premium_')));
        self::assertLessThan(100_000 + 100, $after['gold'], 'gold spent');
    }

    public function testLeaguesKeepOneSeatForThePlayer(): void
    {
        $leagues = BotWorld::leagues(range(1, 45), '2026-W43', 20, static fn (int $id): int => $id);

        self::assertSame([19, 20, 6], array_map('count', $leagues));
        self::assertSame(range(1, 45), (static function (array $all): array { sort($all); return $all; })(array_merge(...$leagues)));
    }

    public function testTournamentPlaysAgainstTheBots(): void
    {
        $opponents = new class () implements TournamentOpponents {
            public function league(string $week, int $tier, int $at, string $seed = ''): ?array
            {
                $bots = [];
                for ($i = 1; $i <= 19; $i++) {
                    $bots[] = ['id' => $i, 'name' => "Bot {$i}", 'title' => $i % 2 ? '90' : '50', 'points' => $i * 100];
                }

                return $bots;
            }
        };
        $engine = (new Engine($this->data))->withOpponents($opponents);
        $state = json_decode(json_encode($engine->newState()), true);
        $state['market_xp'] = 1550;

        $view = json_decode(json_encode($engine->view($engine->resolve($state, self::MONDAY + 3600), self::MONDAY + 3600)), true)['tournament'];
        self::assertSame('Bot 19', $view['ranking'][0]['name']);
        self::assertSame('90', $view['ranking'][0]['title']);
        self::assertSame(19, $view['ranking'][0]['id']);
        self::assertCount(20, $view['ranking']);
    }
}
