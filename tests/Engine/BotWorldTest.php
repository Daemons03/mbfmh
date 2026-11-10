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
            self::assertGreaterThanOrEqual(2, count(array_filter($bot['buildings'], static fn (array $b): bool => $b[0] === 'field')), 'the 2 starting fields (and what day 1 bought)');
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
        $bot['buildings'] = array_fill(0, 6, ['field', 5]); // enough corn for the chain to be worth it
        $fieldsOnly = $this->world->produce($bot, 86400);

        // A premium coop (×1.6 eggs), as the bots build: worth its corn once fed.
        $bot['buildings'][] = ['premium_chicken_coop', 1];
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
        // 60 bots in bronze 1★: all in lots A (1-20) and B (21-60), all go up.
        self::assertSame([1 => 60], array_count_values(array_column($world['bots'], 'tier')));
        foreach ($world['bots'] as $bot) {
            self::assertSame(0, $bot['history']['2026-W43'][0]);
            self::assertSame($bot['day_start_mxp'], $bot['week_start_mxp'], 'the new week starts with Monday');
        }
        self::assertSame([], $world['meta']['results']['2026-W43'], 'no real farm that week');
    }

    /**
     * @return list<array<string, mixed>> $count bots of $tier, the last one with the most points
     */
    private function league(string $seed, int $tier, int $count): array
    {
        $bots = array_slice($this->world->create(self::MONDAY, $seed)['bots'], 0, $count);
        foreach ($bots as $i => $bot) {
            $bots[$i]['tier'] = $tier;
            $bots[$i]['gold'] = 0;
            $bots[$i]['week_start_mxp'] = 0;
            $bots[$i]['market_xp'] = 100 * ($i + 1);
        }

        return $bots;
    }

    public function testOneLeaguePerTierWithItsLots(): void
    {
        // Sapphire 2★ (tier 13), 10 places: A 1, B 2-3 go up, C 4-7 stay, D 8-10 go down.
        $closed = $this->world->closeWeek($this->league('down', 13, 10), '2026-W43')['bots'];
        self::assertSame([14, 14, 14, 13, 13, 13, 13, 12, 12, 12], array_reverse(array_column($closed, 'tier')));
        // Gold × 14: 1 000 / 500 / 250 / 100.
        self::assertSame([14000, 7000, 7000, 3500, 3500, 3500, 3500, 1400, 1400, 1400], array_reverse(array_column($closed, 'gold')));

        // More than the places: everybody beyond the last lot goes down too.
        $closed = $this->world->closeWeek($this->league('full', 13, 12), '2026-W43')['bots'];
        self::assertSame(5, array_count_values(array_column($closed, 'tier'))[12]);
    }

    public function testTheLastOfBronzeGetsABoost(): void
    {
        $bots = $this->league('loser', 0, 5); // the first one has the fewest points
        $bots[0]['activity'] = 0.5;
        $bots[0]['title'] = '50';
        $bots[0]['cash'] = 0;
        $closed = $this->world->closeWeek($bots, '2026-W43')['bots'];

        self::assertSame(100_000_000, $closed[0]['cash']);
        self::assertSame(1_000_000 + 1000, $closed[0]['gold'], 'the prize + lot A gold (5 bots: all in lot A)');
        self::assertSame(20_000, $closed[0]['materials']['mallets']);
        self::assertSame(0.6, $closed[0]['activity']);
        self::assertSame('60', $closed[0]['title']);
        self::assertSame(1, $closed[0]['loser']);
        self::assertArrayNotHasKey('loser', $closed[1]);

        // A real farm last: the place is all the bot world records, the farm pays itself.
        $closed = $this->world->closeWeek($this->league('farm-last', 0, 5), '2026-W43', [['seed' => 'me', 'tier' => 0, 'points' => 0]]);
        self::assertSame(['rank' => 6, 'size' => 6], $closed['results']['me']);
        self::assertArrayNotHasKey('loser', $closed['bots'][0]);
    }

    public function testRealFarmsPlayInTheSameLeague(): void
    {
        $farms = [
            ['seed' => 'top', 'tier' => 13, 'points' => 5_000],
            ['seed' => 'tie', 'tier' => 13, 'points' => 900], // ties with the best bot: real farms first
            ['seed' => 'other', 'tier' => 2, 'points' => 0],
        ];
        $closed = $this->world->closeWeek($this->league('farms', 13, 9), '2026-W43', $farms);

        self::assertSame(['top' => ['rank' => 1, 'size' => 11], 'tie' => ['rank' => 2, 'size' => 11], 'other' => ['rank' => 1, 'size' => 1]], $closed['results']);
        $tiers = array_reverse(array_column($closed['bots'], 'tier'));
        self::assertSame(14, $tiers[0], 'the best bot, 3rd: lot B');
        self::assertSame(13, $tiers[1], '4th: lot C');
        self::assertSame([12, 12, 12, 12], array_slice($tiers, 5), '8th to 11th: lot D and beyond');
    }

    public function testWeekResultsAreKept(): void
    {
        $world = $this->world->create(self::MONDAY, 'results');
        $farms = static fn (string $week): array => [['seed' => 'mine', 'tier' => 0, 'points' => 10_000]];
        $world = $this->world->advance($world['meta'], $world['bots'], self::MONDAY + 7 * 86400 + 2 * 3600, null, $farms);

        self::assertSame(['mine' => ['rank' => 1, 'size' => 61]], $world['meta']['results']['2026-W43']);
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

            public function result(string $week, string $seed): ?array
            {
                return $week === '2026-W43' ? ['rank' => 2, 'size' => 10] : null;
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
        self::assertSame([1, 20], $view['lots']['first'], 'the lots of bronze 1★');
        self::assertSame('first', $view['group']);
    }

    public function testTheWeekClosesWithThePlaceOfEverybody(): void
    {
        $opponents = new class () implements TournamentOpponents {
            public function league(string $week, int $tier, int $at, string $seed = ''): ?array
            {
                return [['id' => 1, 'name' => 'Bot', 'title' => '90', 'points' => 10]];
            }

            public function result(string $week, string $seed): ?array
            {
                return $week === '2026-W43' ? ['rank' => 9, 'size' => 10] : null;
            }
        };
        $engine = (new Engine($this->data))->withOpponents($opponents);
        $state = json_decode(json_encode($engine->resolve($engine->newState(), self::MONDAY + 3600)), true);
        $state['tournament']['tier'] = 13;
        $state['market_xp'] = 99_999; // first live, but the week's result says 9th

        $state = json_decode(json_encode($engine->resolve($state, self::MONDAY + 7 * 86400 + 3 * 3600)), true);
        $last = $state['tournament']['last'];
        self::assertSame(['new_tier' => 12, 'rank' => 9, 'size' => 10, 'group' => 'relegated'], array_intersect_key($last, ['rank' => 0, 'size' => 0, 'group' => 0, 'new_tier' => 0]));
    }
}
