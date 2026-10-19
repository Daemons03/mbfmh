<?php

declare(strict_types=1);

namespace FermeTranquille\Engine;

use DateTimeImmutable;
use DateTimeZone;
use FermeTranquille\GameData;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The bots of the ranking and the tournament: a simulated world, advanced one
 * day at a time (server time zone). Pure: arrays in, arrays out.
 *
 * meta = { seed, day, day_start, week, speed, created_at }
 * bot  = { id, name, title, activity, level, xp, cash, market_xp, materials,
 *          expansions, buildings: [[type, level], ...], tier,
 *          week_start_mxp, day_start_mxp, day_gain, history: {week: [tier, gain]} }
 *
 * Each day a bot runs its whole farm for 24 h × speed × activity, with the
 * game's recipes and durations; chains are respected (fields feed the
 * windmill, which feeds the animals...). What is left is sold: market
 * products as contracts (cash, XP, market XP and materials like the
 * player's), the rest at the barn. Then it levels up and spends: land,
 * houses, new buildings, upgrades. No gold: no premium, no variants.
 *
 * Tournament: every Monday the bots of each tier play their leagues of
 * `size` (first league one short, the player's seat), ranked by the market
 * XP of the week; the first `promoted` go up, the last `relegated` down.
 */
final class BotWorld
{
    private const int DAY = 86400;

    /** @var array<int, array<string, mixed>> precomputed tables per GameData */
    private static array $tables = [];

    public function __construct(private readonly GameData $data)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->data->toArray()['bots'];
    }

    // ------------------------------------------------------------ calendar

    /** Midnight (server time zone) of the day containing $now. */
    public static function dayStart(int $now): int
    {
        $date = (new DateTimeImmutable("@{$now}"))->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $date->setTime(0, 0)->getTimestamp();
    }

    private static function nextDayStart(int $dayStart): int
    {
        // Through the calendar, not + 86400: days around a clock change last 23 or 25 hours.
        $date = (new DateTimeImmutable("@{$dayStart}"))->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $date->modify('+1 day')->setTime(0, 0)->getTimestamp();
    }

    // ------------------------------------------------------------ creation and time

    /**
     * A new world: `count` bots at level 1 in the lowest tier, today already simulated.
     *
     * @return array{meta: array<string, mixed>, bots: list<array<string, mixed>>}
     */
    public function create(int $now, string $seed, float $speed = 1.0): array
    {
        $config = $this->config();
        $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', "bots:{$seed}", true)));
        $names = $random->shuffleArray($config['names']);
        $initial = $this->data->initialState();
        $buildings = array_values(array_filter(
            $this->startingBuildings(),
            fn (array $b): bool => isset($this->tables()['usable'][$b[0]]),
        ));
        $profiles = $random->shuffleArray($this->profileSlots($config['count']));

        $bots = [];
        for ($i = 0; $i < $config['count']; $i++) {
            $profile = $profiles[$i];
            $round = intdiv($i, count($names));
            $bots[] = [
                'id' => $i + 1,
                'name' => $names[$i % count($names)] . ($round > 0 ? ' ' . self::roman($round + 1) : ''),
                'title' => $profile['title'],
                'activity' => $profile['activity'],
                'level' => 1, 'xp' => 0,
                'cash' => $initial['cash'] ?? 0, 'market_xp' => 0,
                'materials' => [], 'expansions' => $this->data->initialExpansions(),
                'buildings' => $buildings,
                'tier' => 0,
                'week_start_mxp' => 0, 'day_start_mxp' => 0, 'day_gain' => 0,
                'history' => [],
            ];
        }

        $today = self::dayStart($now);
        $meta = [
            'seed' => $seed, 'speed' => $speed, 'created_at' => $now,
            'day_start' => $today, 'week' => Tournament::week($today)['id'],
        ];
        // Simulate the first day right away so the bots have a score today.
        foreach ($bots as $i => $bot) {
            $bots[$i] = $this->simulateDay($bot, $meta, $today);
        }

        return ['meta' => $meta, 'bots' => $bots];
    }

    /**
     * Days still to simulate up to $now (0 when the world is up to date).
     *
     * @param array<string, mixed> $meta
     */
    public function daysBehind(array $meta, int $now): int
    {
        $days = 0;
        for ($day = $meta['day_start']; $day < self::dayStart($now) && $days < 10_000; $day = self::nextDayStart($day)) {
            $days++;
        }

        return $days;
    }

    /**
     * Simulates the days between the last one and $now (at most
     * max_catch_up_days at once). Mondays first close the bots' week.
     *
     * @param array<string, mixed> $meta
     * @param list<array<string, mixed>> $bots
     * @return array{meta: array<string, mixed>, bots: list<array<string, mixed>>}
     */
    public function advance(array $meta, array $bots, int $now, ?int $days = null): array
    {
        $limit = $days ?? $this->config()['max_catch_up_days'];
        $today = self::dayStart($now);
        for ($done = 0; ($days !== null || $meta['day_start'] < $today) && $done < $limit; $done++) {
            $day = self::nextDayStart($meta['day_start']);
            $week = Tournament::week($day)['id'];
            if ($week !== $meta['week']) {
                $bots = $this->closeWeek($bots, $meta['week']);
                $meta['week'] = $week;
            }
            foreach ($bots as $i => $bot) {
                $bots[$i] = $this->simulateDay($bot, $meta, $day);
            }
            $meta['day_start'] = $day;
        }

        return ['meta' => $meta, 'bots' => $bots];
    }

    // ------------------------------------------------------------ one day

    /**
     * @param array<string, mixed> $bot
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function simulateDay(array $bot, array $meta, int $day): array
    {
        $config = $this->config();
        $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', "{$meta['seed']}:{$bot['id']}:{$day}", true)));
        $jitter = $config['daily_jitter'] * (2 * $random->nextFloat() - 1);
        $activity = max(0.0, min(1.0, $bot['activity'] * (1 + $jitter)));
        $seconds = self::DAY * (float) $meta['speed'] * $activity;

        $result = $this->produce($bot, $seconds);
        $bot['cash'] += $result['revenue'] - $result['cost'];
        $bot['xp'] += $result['xp'];
        foreach ($result['materials'] as $material => $quantity) {
            $bot['materials'][$material] = ($bot['materials'][$material] ?? 0) + $quantity;
        }
        $bot['day_start_mxp'] = $bot['market_xp'];
        $bot['market_xp'] += $result['market_xp'];
        $bot['day_gain'] = $result['market_xp'];

        $bot = $this->levelUp($bot);

        return $this->spend($bot, $result['cost_full']);
    }

    /**
     * One day of the whole farm. Recipes that are worth less than selling
     * their inputs (XP counted at xp_value) are dropped; when cash cannot pay
     * the whole day, the fields plant the best crop with what there is.
     * cost_full: what the whole day costs with enough cash (the reserve to keep).
     *
     * @param array<string, mixed> $bot
     * @return array{revenue: int, cost: int, cost_full: int, xp: int, market_xp: int, materials: array<string, int>}
     */
    public function produce(array $bot, float $seconds): array
    {
        // A recipe worth less than its inputs alone (the windmill: corn sells
        // better than feed) is dropped only if the farm does better without it,
        // whole chain counted (the feed makes eggs).
        $jobs = $this->jobs($bot, $seconds);
        $run = $this->run($bot, $seconds, $jobs);
        $losing = array_filter($run['worth'], static fn (float $worth): bool => $worth < 0);
        asort($losing);
        foreach (array_keys($losing) as $index) {
            $without = $jobs;
            unset($without[$index]);
            $other = $this->run($bot, $seconds, $without);
            if ($this->utility($bot, $seconds, $other) > $this->utility($bot, $seconds, $run)) {
                [$jobs, $run] = [$without, $other];
            }
        }
        $full = $run['cost'];
        if ($run['cost'] > $bot['cash']) {
            // Short of cash: only the best crop, as much as the cash pays for.
            $run = $this->run($bot, $seconds, []);
            if ($run['cost'] > $bot['cash']) {
                $run = $this->run($bot, $seconds * max(0.0, $bot['cash']) / $run['cost'], []);
            }
        }

        return $this->sell($bot, $seconds, $run) + ['cost' => (int) ceil($run['cost']), 'cost_full' => (int) ceil($full)];
    }

    /**
     * Recipes each production building runs today and their run cap: the
     * ones the chains need, plus the building's best other one.
     *
     * @param array<string, mixed> $bot
     * @return list<array{0: int, 1: string, 2: array<string, mixed>, 3: float}> [stage, recipe id, recipe, runs]
     */
    private function jobs(array $bot, float $seconds): array
    {
        $t = $this->tables();
        $items = $this->data->toArray()['items'];
        $level = $bot['level'];
        $maxRuns = $this->config()['max_runs_per_day'];

        $makers = [];
        $producible = array_fill_keys($this->crops($bot), true);
        foreach ($bot['buildings'] as $index => [$type, $buildingLevel]) {
            if ($t['usable'][$type] !== 'production') {
                continue;
            }
            $recipes = array_filter(
                $this->data->buildingLevel($type, $buildingLevel)['recipes'] ?? [],
                static fn (array $r, string $id): bool => $items[$id]['unlock_level'] <= $level && ($r['duration'] ?? 0) > 0,
                ARRAY_FILTER_USE_BOTH,
            );
            if ($recipes !== []) {
                $makers[$index] = $recipes;
            }
        }
        do {
            $grew = false;
            foreach ($makers as $recipes) {
                foreach ($recipes as $recipe) {
                    if (array_diff_key($recipe['inputs'], $producible) === []) {
                        foreach ($recipe['outputs'] as $item => $_) {
                            if (!isset($producible[$item])) {
                                $producible[$item] = $grew = true;
                            }
                        }
                    }
                }
            }
        } while ($grew);

        $feasible = [];
        $needed = [];
        foreach ($makers as $index => $recipes) {
            $feasible[$index] = array_filter($recipes, static fn (array $r): bool => array_diff_key($r['inputs'], $producible) === []);
            foreach ($feasible[$index] as $recipe) {
                $needed += array_fill_keys(array_keys($recipe['inputs']), true);
            }
        }
        $jobs = [];
        foreach ($feasible as $recipes) {
            $chosen = array_filter($recipes, static fn (string $id): bool => isset($needed[$id]), ARRAY_FILTER_USE_KEY);
            $others = array_diff_key($recipes, $chosen);
            uasort($others, fn (array $a, array $b): int => $this->recipeValue($b) <=> $this->recipeValue($a));
            $best = array_key_first($others);
            if ($best !== null && $this->recipeValue($others[$best]) > 0) {
                $chosen[$best] = $others[$best];
            }
            foreach ($chosen as $id => $recipe) {
                $runs = $seconds / count($chosen) / $recipe['duration'];
                if ($maxRuns !== null) {
                    $runs = min($runs, $maxRuns / count($chosen));
                }
                $jobs[] = [$t['stages'][$id] ?? 9, $id, $recipe, $runs];
            }
        }
        usort($jobs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // Feed, fertilizer...: only what the next buildings can use (the rest would sell for little).
        for ($i = count($jobs) - 1; $i >= 0; $i--) {
            [, $id, $recipe] = $jobs[$i];
            if ($items[$id]['marketable'] ?? false) {
                continue;
            }
            $demand = 0.0;
            foreach ($jobs as [, , $consumer, $runs]) {
                $demand += $runs * ($consumer['inputs'][$id] ?? 0);
            }
            if ($demand > 0) {
                $producers = count(array_filter($jobs, static fn (array $job): bool => $job[1] === $id));
                $jobs[$i][3] = min($jobs[$i][3], $demand / $recipe['outputs'][$id] / $producers);
            }
        }

        return $jobs;
    }

    /**
     * Fields (crops the jobs need first, then the best crop) and the jobs along
     * the chains as far as the inputs go. worth: what each job adds compared
     * with selling its inputs, XP included.
     *
     * @param array<string, mixed> $bot
     * @param list<array{0: int, 1: string, 2: array<string, mixed>, 3: float}> $jobs
     * @return array{stock: array<string, float>, cost: float, xp: float, worth: array<int, float>}
     */
    private function run(array $bot, float $seconds, array $jobs): array
    {
        $t = $this->tables();
        $factor = $t['cost_factor'];
        $xpValue = $this->config()['xp_value'];
        $maxRuns = $this->config()['max_runs_per_day'];
        $crops = $this->crops($bot);
        $fields = array_values(array_filter($bot['buildings'], fn (array $b): bool => $t['usable'][$b[0]] === 'field'));
        $stock = [];
        $cost = 0.0;
        $xp = 0.0;

        if ($fields !== [] && $crops !== []) {
            $fieldLevel = (int) round(array_sum(array_column($fields, 1)) / count($fields));
            $perSecond = fn (string $crop): float => $this->cropYield($crop, $fieldLevel) / $t['crops'][$crop]['duration'];
            $demand = [];
            foreach ($jobs as [, , $recipe, $runs]) {
                foreach ($recipe['inputs'] as $item => $quantity) {
                    if (isset($t['crops'][$item]) && in_array($item, $crops, true)) {
                        $demand[$item] = ($demand[$item] ?? 0) + $runs * $quantity;
                    }
                }
            }
            $fieldSeconds = count($fields) * $seconds;
            $wanted = array_sum(array_map(static fn (string $c, float $q): float => $q / $perSecond($c), array_keys($demand), $demand));
            $share = $wanted > $fieldSeconds ? $fieldSeconds / $wanted : 1.0;
            $plan = [];
            foreach ($demand as $crop => $quantity) {
                $plan[$crop] = $quantity / $perSecond($crop) * $share;
            }
            $left = $fieldSeconds - array_sum($plan);
            $best = $this->bestCrop($crops, $fieldLevel);
            if ($left > 0 && $best !== null) {
                $plan[$best] = ($plan[$best] ?? 0) + $left;
            }
            foreach ($plan as $crop => $time) {
                $data = $t['crops'][$crop];
                $runs = $time / $data['duration'];
                if ($maxRuns !== null) {
                    $runs = min($runs, $maxRuns * count($fields));
                }
                $stock[$crop] = ($stock[$crop] ?? 0) + $runs * $this->cropYield($crop, $fieldLevel);
                $nectar = $data['flower_nectar'][min($fieldLevel, count($data['flower_nectar'])) - 1] ?? 0;
                if ($nectar > 0) {
                    $stock['flower_nectar'] = ($stock['flower_nectar'] ?? 0) + $runs * $nectar;
                }
                $cost += $runs * ($data['plant_cost']['cash'] ?? 0) * $factor;
                $xp += $runs * $data['xp'];
            }
        }

        $worth = [];
        foreach ($jobs as $index => [, , $recipe, $cap]) {
            $runs = $cap;
            foreach ($recipe['inputs'] as $item => $quantity) {
                $runs = min($runs, ($stock[$item] ?? 0) / $quantity);
            }
            $worth[$index] = 0.0;
            if ($runs <= 0) {
                continue;
            }
            $cash = ($recipe['cost']['cash'] ?? 0) * $factor;
            foreach ($recipe['inputs'] as $item => $quantity) {
                $stock[$item] -= $runs * $quantity;
            }
            foreach ($recipe['outputs'] as $item => $quantity) {
                $stock[$item] = ($stock[$item] ?? 0) + $runs * $quantity;
            }
            $cost += $runs * $cash;
            $xp += $runs * ($recipe['xp'] ?? 0);
            $worth[$index] = $runs * ($this->recipeValue($recipe, $factor) * $recipe['duration'] + ($recipe['xp'] ?? 0) * $xpValue);
        }

        return ['stock' => $stock, 'cost' => $cost, 'xp' => $xp, 'worth' => $worth];
    }

    /**
     * What a day is worth to the bot: stock at sale price minus cash spent, XP at xp_value.
     *
     * @param array{stock: array<string, float>, cost: float, xp: float} $run
     */
    private function utility(array $bot, float $seconds, array $run): float
    {
        $sale = $this->sales($bot, $seconds, $run['stock']);

        return $run['xp'] * $this->config()['xp_value'] - $run['cost'] + $sale['market'] + $sale['barn'];
    }

    /**
     * Where the stock sells: the market asks contracts_per_day contracts a
     * full day (fewer when the bot plays less), each 1 to 3 products drawn
     * among what it makes, quantities of its level; past that demand a
     * product goes to the barn.
     *
     * @param array<string, mixed> $bot
     * @param array<string, float> $stock
     * @return array{market: float, barn: float, contracts: float}
     */
    private function sales(array $bot, float $seconds, array $stock): array
    {
        $items = $this->data->toArray()['items'];
        $marketData = $this->data->market();
        [$min, $max] = (new Market($this->data))->quantityRange($bot['level']);
        [$fewest, $most] = $marketData['products_per_contract'];
        $products = array_filter($stock, static fn (float $q, string $id): bool => $q > 0 && ($items[$id]['marketable'] ?? false), ARRAY_FILTER_USE_BOTH);
        $contracts = $this->config()['contracts_per_day'] * $seconds / self::DAY;
        $perProduct = $products === [] ? 0.0 : $contracts * ($fewest + $most) / 2 / count($products) * ($min + $max) / 2;

        $market = 0.0;
        $barn = 0.0;
        $sold = 0.0;
        foreach ($stock as $item => $quantity) {
            if ($quantity <= 0 || ($items[$item]['sellable'] ?? true) === false) {
                continue;
            }
            $toMarket = isset($products[$item]) ? min($quantity, $perProduct) : 0.0;
            $market += $toMarket * $items[$item]['market_price'];
            $barn += ($quantity - $toMarket) * $items[$item]['barn_price'];
            $sold += $toMarket;
        }
        $perContract = ($fewest + $most) / 2 * ($min + $max) / 2;

        return ['market' => $market, 'barn' => $barn, 'contracts' => $sold > 0 ? max(1.0, $sold / $perContract) : 0.0];
    }

    /**
     * Sales of the day (see sales()): contracts bring cash, XP, market XP and
     * materials like the player's; the rest sells at the barn.
     *
     * @param array<string, mixed> $bot
     * @param array{stock: array<string, float>, cost: float, xp: float} $run
     * @return array{revenue: int, xp: int, market_xp: int, materials: array<string, int>}
     */
    private function sell(array $bot, float $seconds, array $run): array
    {
        $sale = $this->sales($bot, $seconds, $run['stock']);
        $marketData = $this->data->market();
        $marketXp = 0.0;
        $materials = [];
        if ($sale['market'] > 0) {
            $marketXp = $sale['contracts'] * Market::marketXpFor($this->data, (int) round($sale['market'] / $sale['contracts']));
            $reward = $marketData['material_reward'];
            foreach ($reward['materials'] as $material => $weight) {
                // Each contract brings one material at random: its expected share.
                $materials[$material] = (int) floor($sale['market'] * $reward['per_cash'] * $weight / count($reward['materials']));
            }
        }

        return [
            'revenue' => (int) floor($sale['market'] + $sale['barn']),
            'xp' => (int) floor($run['xp'] + $sale['market'] * $marketData['xp_per_cash']),
            'market_xp' => (int) floor($marketXp),
            'materials' => array_filter($materials),
        ];
    }

    /**
     * @param array<string, mixed> $bot
     * @return list<string> crops unlocked at the bot's level
     */
    private function crops(array $bot): array
    {
        $items = $this->data->toArray()['items'];

        return array_values(array_filter(array_keys($this->tables()['crops']), static fn (string $c): bool => $items[$c]['unlock_level'] <= $bot['level']));
    }

    /** @param list<string> $crops */
    private function bestCrop(array $crops, int $fieldLevel): ?string
    {
        usort($crops, fn (string $a, string $b): int => $this->cropValue($b, $fieldLevel) <=> $this->cropValue($a, $fieldLevel));

        return $crops !== [] && $this->cropValue($crops[0], $fieldLevel) > 0 ? $crops[0] : null;
    }

    // ------------------------------------------------------------ level and purchases

    /**
     * @param array<string, mixed> $bot
     * @return array<string, mixed>
     */
    private function levelUp(array $bot): array
    {
        while ($bot['level'] < $this->data->maxLevel() && $bot['xp'] >= $this->data->xpForLevel($bot['level'])) {
            $bot['xp'] -= $this->data->xpForLevel($bot['level']);
            $bot['level']++;
        }

        return $bot;
    }

    /**
     * Spends what is left after keeping enough to run the whole farm a full
     * day: new buildings first (land and houses when they lack), then upgrades.
     *
     * @param array<string, mixed> $bot
     * @return array<string, mixed>
     */
    private function spend(array $bot, int $fullDayCost): array
    {
        $reserve = $fullDayCost;
        for ($n = 0; $n < $this->config()['purchases_per_day']; $n++) {
            $next = $this->nextPurchase($bot, $reserve);
            if ($next === null) {
                break;
            }
            $bot = $next;
        }

        return $bot;
    }

    /**
     * The bot after its next purchase, or null when nothing fits the budget.
     *
     * @param array<string, mixed> $bot
     * @return array<string, mixed>|null
     */
    private function nextPurchase(array $bot, int $reserve): ?array
    {
        // A wanted building, or what it lacks (workers, land); a blocked one leaves its turn to the next.
        foreach ($this->wantedBuildings($bot) as $type) {
            $first = $this->data->building($type)['levels'][0];
            $need = max(0, -($first['workers'] ?? 0));
            $step = match (true) {
                $this->freeWorkers($bot) < $need => $this->moreWorkers($bot, $reserve),
                $this->freeLand($bot) < $this->area($type) => $this->moreLand($bot, $reserve),
                $this->affordable($bot, $first['cost'], $reserve) => $this->build($bot, $type),
                default => null,
            };
            if ($step !== null) {
                return $step;
            }
        }

        $upgrades = [];
        foreach ($bot['buildings'] as $index => [$type, $level]) {
            $next = $this->data->buildingLevel($type, $level + 1);
            if ($next !== null && ($next['unlock_level'] ?? 1) <= $bot['level']) {
                $upgrades[] = [$index, $next];
            }
        }
        usort($upgrades, static fn (array $a, array $b): int => ($a[1]['cost']['cash'] ?? 0) <=> ($b[1]['cost']['cash'] ?? 0));
        foreach ($upgrades as [$index, $next]) {
            [$type, $level] = $bot['buildings'][$index];
            $current = $this->data->buildingLevel($type, $level);
            $extra = max(0, -($next['workers'] ?? 0)) - max(0, -($current['workers'] ?? 0));
            if ($extra > $this->freeWorkers($bot) || !$this->affordable($bot, $next['cost'], $reserve)) {
                continue;
            }
            $bot = $this->pay($bot, $next['cost']);
            $bot['buildings'][$index][1]++;

            return $bot;
        }

        return null;
    }

    /**
     * Types the bot wants one more of now, most wanted first: one of each
     * unlocked type, then more fields, animals and orchards as it grows.
     *
     * @param array<string, mixed> $bot
     * @return list<string>
     */
    private function wantedBuildings(array $bot): array
    {
        $t = $this->tables();
        $level = $bot['level'];
        $counts = array_count_values(array_column($bot['buildings'], 0));
        $animals = 0;
        $orchards = 0;
        foreach ($counts as $type => $count) {
            $animals += ($t['roles'][$type] ?? '') === 'animal' ? $count : 0;
            $orchards += ($t['roles'][$type] ?? '') === 'orchard' ? $count : 0;
        }

        $wanted = [];
        foreach ($t['buildable'] as $type => $role) {
            $definition = $this->data->building($type);
            if (($definition['levels'][0]['unlock_level'] ?? 1) > $level) {
                continue;
            }
            $target = match ($role) {
                'field' => 2 + intdiv($level, 2),
                'animal' => 1 + intdiv($level, 15),
                'orchard' => 1 + intdiv($level, 20),
                'windmill' => $animals >= 4 ? 2 : 1,
                'silo' => $orchards === 0 ? 0 : ($orchards >= 4 ? 2 : 1),
                'meadow' => 1 + intdiv($level, 30),
                default => 1,
            };
            if ($definition['max_count'] !== null) {
                $target = min($target, $definition['max_count']);
            }
            $have = $counts[$type] ?? 0;
            if ($have < $target) {
                $wanted[] = [$have, $definition['levels'][0]['unlock_level'] ?? 1, $type];
            }
        }
        sort($wanted); // missing types first, then the earliest unlocked

        return array_column($wanted, 2);
    }

    /**
     * @param array<string, mixed> $bot
     * @return array<string, mixed>
     */
    private function build(array $bot, string $type): array
    {
        $bot = $this->pay($bot, $this->data->building($type)['levels'][0]['cost']);
        $bot['buildings'][] = [$type, 1];

        return $bot;
    }

    /**
     * A new house (or the land for it), else an upgrade of a house or the farmhouse.
     *
     * @param array<string, mixed> $bot
     * @return array<string, mixed>|null
     */
    private function moreWorkers(array $bot, int $reserve): ?array
    {
        $house = $this->tables()['house'];
        $first = $this->data->building($house)['levels'][0];
        $houses = count(array_filter($bot['buildings'], static fn (array $b): bool => $b[0] === $house));
        $max = $this->data->building($house)['max_count'];
        if (($first['unlock_level'] ?? 1) <= $bot['level'] && ($max === null || $houses < $max)) {
            if ($this->freeLand($bot) < $this->area($house)) {
                $land = $this->moreLand($bot, $reserve);
                if ($land !== null) {
                    return $land;
                }
            } elseif ($this->affordable($bot, $first['cost'], $reserve)) {
                return $this->build($bot, $house);
            }
        }
        foreach ($bot['buildings'] as $index => [$type, $level]) {
            $next = $this->data->buildingLevel($type, $level + 1);
            $current = $this->data->buildingLevel($type, $level);
            if ($next !== null && ($next['workers'] ?? 0) > ($current['workers'] ?? 0) && ($current['workers'] ?? 0) > 0
                && ($next['unlock_level'] ?? 1) <= $bot['level'] && $this->affordable($bot, $next['cost'], $reserve)) {
                $bot = $this->pay($bot, $next['cost']);
                $bot['buildings'][$index][1]++;

                return $bot;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $bot */
    private function moreLand(array $bot, int $reserve): ?array
    {
        $expansion = $this->data->expansion($bot['expansions'] + 1);
        if ($expansion === null || ($expansion['unlock_level'] ?? 1) > $bot['level']) {
            return null;
        }
        foreach ($expansion['costs'] as $cost) {
            if (!isset($cost['gold']) && $this->affordable($bot, $cost, $reserve)) {
                $bot = $this->pay($bot, $cost);
                $bot['expansions']++;

                return $bot;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $bot
     * @param array<string, int> $cost
     */
    private function affordable(array $bot, array $cost, int $reserve): bool
    {
        foreach ($cost as $resource => $quantity) {
            $have = match ($resource) {
                'cash' => $bot['cash'] - $reserve,
                'gold' => 0,
                default => $bot['materials'][$resource] ?? 0,
            };
            if ($have < $quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $bot
     * @param array<string, int> $cost
     * @return array<string, mixed>
     */
    private function pay(array $bot, array $cost): array
    {
        foreach ($cost as $resource => $quantity) {
            if ($resource === 'cash') {
                $bot['cash'] -= $quantity;
            } else {
                $bot['materials'][$resource] -= $quantity;
            }
        }

        return $bot;
    }

    /** @param array<string, mixed> $bot */
    public function freeWorkers(array $bot): int
    {
        $free = 0;
        foreach ($bot['buildings'] as [$type, $level]) {
            $free += $this->data->buildingLevel($type, $level)['workers'] ?? 0;
        }

        return $free;
    }

    /** @param array<string, mixed> $bot */
    private function freeLand(array $bot): float
    {
        $block = $this->data->blockSize();
        $used = 0;
        foreach ($bot['buildings'] as [$type]) {
            $used += $this->area($type);
        }

        return $bot['expansions'] * $block * $block * $this->config()['land_use'] - $used;
    }

    private function area(string $type): int
    {
        $size = $this->data->building($type)['size'] ?? [0, 0];

        return $size[0] * $size[1];
    }

    // ------------------------------------------------------------ weeks and leagues

    /**
     * Closes the bots' week: history, then promotion / relegation in each league.
     *
     * @param list<array<string, mixed>> $bots
     * @return list<array<string, mixed>>
     */
    public function closeWeek(array $bots, string $week): array
    {
        $tournament = $this->data->toArray()['tournament'];
        $top = count($tournament['leagues']) * $tournament['stars'] - 1;
        $keep = $this->config()['history_weeks'];

        $byTier = [];
        foreach ($bots as $i => $bot) {
            $gain = $bot['market_xp'] - $bot['week_start_mxp'];
            $bots[$i]['history'][$week] = [$bot['tier'], $gain];
            $bots[$i]['history'] = array_slice($bots[$i]['history'], -$keep, null, true);
            $byTier[$bot['tier']][] = $i;
        }
        foreach ($byTier as $tier => $indexes) {
            foreach (self::leagues($indexes, $week, $tournament['size'], static fn (int $i): int => $bots[$i]['id']) as $league) {
                usort($league, static fn (int $a, int $b): int => $bots[$b]['history'][$week][1] <=> $bots[$a]['history'][$week][1]);
                $size = count($league);
                $promoted = min($tournament['promoted'], intdiv($size, 4));
                $relegated = min($tournament['relegated'], intdiv($size, 4));
                foreach ($league as $rank => $i) {
                    if ($rank < $promoted) {
                        $bots[$i]['tier'] = min($top, $tier + 1);
                    } elseif ($rank >= $size - $relegated) {
                        $bots[$i]['tier'] = max(0, $tier - 1);
                    }
                }
            }
        }
        foreach ($bots as $i => $bot) {
            $bots[$i]['week_start_mxp'] = $bot['market_xp'];
        }

        return $bots;
    }

    /**
     * The leagues of one tier for a week: members shuffled by week, the
     * first league one short (the player's seat), then leagues of `size`.
     *
     * @template T
     * @param list<T> $members
     * @param callable(T): int $id
     * @return list<list<T>>
     */
    public static function leagues(array $members, string $week, int $size, callable $id): array
    {
        usort($members, static fn ($a, $b): int => crc32("{$week}:{$id($a)}") <=> crc32("{$week}:{$id($b)}") ?: $id($a) <=> $id($b));
        $leagues = [array_slice($members, 0, $size - 1)];
        for ($offset = $size - 1; $offset < count($members); $offset += $size) {
            $leagues[] = array_slice($members, $offset, $size);
        }

        return array_values(array_filter($leagues));
    }

    // ------------------------------------------------------------ values

    /** Sale value of a recipe per second (outputs minus inputs and cash). */
    private function recipeValue(array $recipe, ?float $factor = null): float
    {
        $value = -($recipe['cost']['cash'] ?? 0) * ($factor ?? $this->tables()['cost_factor']);
        foreach ($recipe['outputs'] as $item => $quantity) {
            $value += $quantity * $this->sellPrice($item);
        }
        foreach ($recipe['inputs'] as $item => $quantity) {
            $value -= $quantity * $this->sellPrice($item);
        }

        return $value / max(1, $recipe['duration']);
    }

    private function cropValue(string $crop, int $fieldLevel): float
    {
        $data = $this->tables()['crops'][$crop];

        return ($this->cropYield($crop, $fieldLevel) * $this->sellPrice($crop) - ($data['plant_cost']['cash'] ?? 0) * $this->tables()['cost_factor']) / $data['duration'];
    }

    private function cropYield(string $crop, int $fieldLevel): int
    {
        $yields = $this->tables()['crops'][$crop]['yields'];

        return $yields[min($fieldLevel, count($yields)) - 1];
    }

    private function sellPrice(string $item): float
    {
        $definition = $this->data->item($item);
        if (($definition['sellable'] ?? true) === false) {
            return 0.0;
        }

        return ($definition['marketable'] ?? false) ? $definition['market_price'] : $definition['barn_price'];
    }

    // ------------------------------------------------------------ tables

    /**
     * usable: type => field | production | house | storage | special (buildings bots keep);
     * buildable: type => role (what they build); roles; house; crops; stages (item => chain depth).
     *
     * @return array<string, mixed>
     */
    private function tables(): array
    {
        $key = spl_object_id($this->data);
        if (isset(self::$tables[$key])) {
            return self::$tables[$key];
        }
        $data = $this->data->toArray();
        $usable = [];
        $buildable = [];
        $roles = [];
        $house = null;
        foreach ($data['buildings'] as $type => $definition) {
            if (($definition['zone'] ?? 'farm') !== 'farm' || isset($definition['variant']) || $definition['kind'] === 'decoration') {
                continue;
            }
            $mode = $definition['production']['mode'] ?? null;
            if ($mode === 'instant') {
                continue; // sewing: crafted goods, not simulated
            }
            $usable[$type] = $definition['kind'];
            $inputs = array_merge([], ...array_map(
                static fn (array $r): array => array_keys($r['inputs'] ?? []),
                array_values($definition['levels'][0]['recipes'] ?? []),
            ));
            $role = match (true) {
                $definition['kind'] === 'field' => 'field',
                $definition['kind'] === 'house' => 'house',
                $type === 'windmill', $type === 'silo', $type === 'meadow' => $type,
                (bool) array_filter($inputs, static fn (string $i): bool => str_ends_with($i, '_feed')) => 'animal',
                in_array('fertilizer', $inputs, true) => 'orchard',
                default => $definition['kind'],
            };
            $roles[$type] = $role;
            if ($role === 'house') {
                $house ??= $type;
            } elseif (in_array($definition['kind'], ['field', 'production'], true)) {
                $buildable[$type] = $role;
            }
        }

        // Chain depth of each item: crops 0, then 1 + the deepest input of its first recipe.
        $stages = array_fill_keys(array_keys($data['crops']), 0);
        for ($pass = 0; $pass < 10; $pass++) {
            foreach ($usable as $type => $_) {
                foreach ($data['buildings'][$type]['levels'] as $level) {
                    foreach ($level['recipes'] ?? [] as $recipe) {
                        $depth = 0;
                        foreach ($recipe['inputs'] as $input => $_q) {
                            $depth = max($depth, ($stages[$input] ?? 0) + 1);
                        }
                        foreach ($recipe['outputs'] as $output => $_q) {
                            if (!isset($data['crops'][$output])) {
                                $stages[$output] = max($stages[$output] ?? 0, $depth ?: 1);
                            }
                        }
                    }
                }
            }
        }

        // Every farm has its starting buildings (the fence): the same happiness discount as the player's.
        $happiness = 0;
        foreach ($this->startingBuildings() as [$type, $level]) {
            $happiness += $this->data->buildingLevel($type, $level)['happiness'] ?? 0;
        }

        return self::$tables[$key] = [
            'usable' => $usable, 'buildable' => $buildable, 'roles' => $roles, 'house' => $house,
            'crops' => $data['crops'], 'stages' => $stages, 'cost_factor' => $this->data->costFactor(max(0, $happiness)),
        ];
    }

    /**
     * What every farm starts with: initial_state buildings and the starters (farmhouse, fence).
     *
     * @return list<array{0: string, 1: int}>
     */
    private function startingBuildings(): array
    {
        $buildings = array_map(static fn (array $b): array => [$b['type'], $b['level'] ?? 1], $this->data->initialState()['buildings']);
        foreach ($this->data->starterBuildings() as $type) {
            $buildings[] = [$type, 1];
        }

        return $buildings;
    }

    /**
     * One profile per bot by their share (20 % at 90, 30 % at 70...); rounding goes to the last profile.
     *
     * @return list<array<string, mixed>>
     */
    private function profileSlots(int $count): array
    {
        $profiles = $this->config()['profiles'];
        $slots = [];
        foreach ($profiles as $profile) {
            $slots = [...$slots, ...array_fill(0, (int) round($count * $profile['share']), $profile)];
        }

        return array_slice([...$slots, ...array_fill(0, $count, end($profiles))], 0, $count);
    }

    private static function roman(int $n): string
    {
        $out = '';
        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $symbol => $value) {
            while ($n >= $value) {
                $out .= $symbol;
                $n -= $value;
            }
        }

        return $out;
    }
}
