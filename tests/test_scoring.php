<?php
/**
 * test_scoring.php — unit tests for the feed's ranking arithmetic.
 *
 * Run:  php backend/tests/test_scoring.php
 *
 * No database and no network, which is the whole reason Scoring is a separate
 * class from FeedService. The ranking policy is the part most likely to be
 * tweaked and the part where a wrong number is least visible — a feed ordered
 * by a broken formula still looks like a feed.
 *
 * The cases that matter most:
 *   - weights sum to exactly 1, so a perfect article scores 1.0
 *   - a zero score still yields a non-zero weight (INF sorts last)
 *   - an undated article sinks rather than floats
 */

declare(strict_types=1);

require __DIR__ . '/../src/Services/Scoring.php';

use App\Services\Scoring;

$pass = 0;
$fail = 0;

function ok(string $m): void  { global $pass; $pass++; printf("  \033[32m[PASS]\033[0m %s\n", $m); }
function bad(string $m): void { global $fail; $fail++; printf("  \033[31m[FAIL]\033[0m %s\n", $m); }

function assert_eq(string $desc, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        ok("$desc (got " . var_export($actual, true) . ")");
    } else {
        bad("$desc — expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function assert_near(string $desc, float $expected, float $actual, float $tol = 0.001): void
{
    if (abs($expected - $actual) <= $tol) {
        ok(sprintf('%s (got %.4f)', $desc, $actual));
    } else {
        bad(sprintf('%s — expected %.4f ± %.4f, got %.4f', $desc, $expected, $tol, $actual));
    }
}

function assert_true(string $desc, bool $cond): void
{
    $cond ? ok($desc) : bad($desc);
}

$now = 1_800_000_000;                 // fixed clock: these tests never flake
$at  = static fn(int $hoursAgo): string => gmdate('Y-m-d H:i:s', $now - $hoursAgo * 3600);

/* ---- weights ------------------------------------------------------- */
echo "\nweights\n";

assert_near('warm weights sum to exactly 1',
    1.0,
    Scoring::W_RECENCY + Scoring::W_CATEGORY + Scoring::W_SOURCE
        + Scoring::W_QUALITY + Scoring::W_EXPLORE,
    0.0);

assert_near('cold weights sum to exactly 1',
    1.0,
    Scoring::COLD_RECENCY + Scoring::COLD_QUALITY + Scoring::COLD_TRENDING,
    0.0);

/* ---- recency ------------------------------------------------------- */
echo "\nrecency — halves every 36 hours\n";

assert_near('published now scores 1.0',        1.0,   Scoring::recency($at(0),   $now));
assert_near('12 hours old',                    0.794, Scoring::recency($at(12),  $now));
assert_near('one half-life (36h) scores 0.5',  0.5,   Scoring::recency($at(36),  $now));
assert_near('two half-lives (72h) scores 0.25',0.25,  Scoring::recency($at(72),  $now));
assert_near('seven days is nearly gone',       0.0394, Scoring::recency($at(168), $now));

assert_eq('undated article sinks to 0.0', 0.0, Scoring::recency(null, $now));
assert_eq('unparseable date sinks to 0.0', 0.0, Scoring::recency('not a date', $now));

// A feed with a bad clock must not be able to outrank everything.
assert_near('future article is capped at 1.0, never above',
    1.0, Scoring::recency(gmdate('Y-m-d H:i:s', $now + 86400), $now));

assert_true('recency is strictly decreasing with age',
    Scoring::recency($at(1), $now) > Scoring::recency($at(2), $now)
    && Scoring::recency($at(2), $now) > Scoring::recency($at(48), $now));

/* ---- quality ------------------------------------------------------- */
echo "\nquality\n";

assert_near('best case scores 1.0',            1.0, Scoring::quality(1.0, true,  true));
assert_near('no image, no excerpt, top source',0.6, Scoring::quality(1.0, false, false));
assert_near('unknown source, image + excerpt', 0.4, Scoring::quality(0.0, true,  true));
assert_near('worst case scores 0.0',           0.0, Scoring::quality(0.0, false, false));
assert_near('image alone is worth 0.2',        0.2, Scoring::quality(0.0, true,  false));

/* ---- smoothing ----------------------------------------------------- */
echo "\nsmoothed rate — evidence beats the prior, gradually\n";

assert_near('no data returns the global prior',
    0.05, Scoring::smoothedRate(0, 0, 0.05, 20));

// One tap in two impressions is a 50% rate, but two impressions prove nothing.
assert_near('tiny sample stays close to the prior',
    0.0909, Scoring::smoothedRate(1, 2, 0.05, 20));

// The same 50% over 1000 impressions is real, and the prior stops mattering.
assert_near('large sample overwhelms the prior',
    0.4912, Scoring::smoothedRate(500, 1000, 0.05, 20));

assert_true('more evidence moves further from the prior',
    abs(Scoring::smoothedRate(50, 100, 0.05, 20) - 0.05)
    > abs(Scoring::smoothedRate(5, 10, 0.05, 20) - 0.05));

/* ---- explore and penalty ------------------------------------------- */
echo "\nexplore and penalty\n";

assert_eq('brand-new source earns the bonus',  1.0, Scoring::explore(0));
assert_eq('4 impressions still earns it',      1.0, Scoring::explore(4));
assert_eq('5 impressions is no longer new',    0.0, Scoring::explore(5));

assert_eq('fatigued source is demoted', 0.3, Scoring::penalty(true));
assert_eq('normal source is untouched', 1.0, Scoring::penalty(false));

/* ---- scores -------------------------------------------------------- */
echo "\nscores\n";

assert_near('a perfect article scores 1.0', 1.0, Scoring::warmScore([
    'recency' => 1.0, 'category' => 1.0, 'source' => 1.0, 'quality' => 1.0, 'explore' => 1.0,
]));

assert_near('an article with nothing scores 0.0', 0.0, Scoring::warmScore([
    'recency' => 0.0, 'category' => 0.0, 'source' => 0.0, 'quality' => 0.0, 'explore' => 0.0,
]));

assert_near('fresh but irrelevant scores exactly the recency weight',
    Scoring::W_RECENCY,
    Scoring::warmScore(['recency' => 1.0, 'category' => 0.0, 'source' => 0.0,
                        'quality' => 0.0, 'explore' => 0.0]));

assert_near('cold: perfect article scores 1.0', 1.0,
    Scoring::coldScore(['recency' => 1.0, 'quality' => 1.0, 'trending' => 1.0]));

// Out-of-range input must not be able to outrank a legitimately perfect row.
assert_near('a corrupt rollup value cannot exceed 1.0', 1.0,
    Scoring::warmScore(['recency' => 99.0, 'category' => 99.0, 'source' => 99.0,
                        'quality' => 99.0, 'explore' => 99.0]));

/* ---- final weight -------------------------------------------------- */
echo "\nfinal weight\n";

assert_near('score passes through when unpenalised', 0.8, Scoring::finalWeight(0.8, 1.0));
assert_near('fatigue multiplies it down',            0.24, Scoring::finalWeight(0.8, 0.3));

// -log(u)/0 is INF, which sorts last forever. The floor is what keeps a
// demoted article rare rather than unreachable.
assert_eq('a zero score is floored, never zeroed',
    Scoring::WEIGHT_FLOOR, Scoring::finalWeight(0.0, 1.0));
assert_eq('a zero penalty is floored too',
    Scoring::WEIGHT_FLOOR, Scoring::finalWeight(0.9, 0.0));

assert_true('the floor is positive, so 1/weight is finite',
    Scoring::WEIGHT_FLOOR > 0);

/* ---- clamp --------------------------------------------------------- */
echo "\nclamp\n";

assert_eq('above range clamps to 1.0', 1.0, Scoring::clamp01(4.2));
assert_eq('below range clamps to 0.0', 0.0, Scoring::clamp01(-1.0));
assert_eq('in range passes through',   0.5, Scoring::clamp01(0.5));
assert_eq('NaN becomes 0.0, not NaN',  0.0, Scoring::clamp01(NAN));

/* ---- breakdown ----------------------------------------------------- */
echo "\nbreakdown — the explainability hook\n";

$terms = ['recency' => 1.0, 'category' => 0.5, 'source' => 0.2, 'quality' => 0.8, 'explore' => 0.0];
$parts = Scoring::breakdown($terms, false);

assert_eq('breakdown names every warm term', 5, count($parts));
assert_eq('largest contribution is listed first', 'recency', array_key_first($parts));
assert_near('contributions sum to the score',
    Scoring::warmScore($terms), array_sum($parts), 0.001);

$coldParts = Scoring::breakdown(['recency' => 1.0, 'quality' => 0.5, 'trending' => 0.0], true);
assert_eq('cold breakdown names three terms', 3, count($coldParts));

/* ------------------------------------------------------------------ */

echo "\n---------------------------\n";
printf("passed: \033[32m%d\033[0m   failed: \033[31m%d\033[0m\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
