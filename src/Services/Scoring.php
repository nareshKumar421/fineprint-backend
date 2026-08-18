<?php
/**
 * Scoring — the feed's ranking arithmetic.
 *
 * Every number in docs/feed-algorithm-simple.md lives here, and NOTHING here
 * touches the database. That is deliberate: the whole ranking policy is one
 * file you can read in a sitting, and tests/test_scoring.php exercises it with
 * no database and no network.
 *
 * The inputs arrive pre-normalised from the nightly rollups (migration 007),
 * so this class only ever adds and multiplies.
 */

declare(strict_types=1);

namespace App\Services;

final class Scoring
{
    /* ---- warm weights: a user with enough history (docs §4) ---- */
    public const W_RECENCY  = 0.40;
    public const W_CATEGORY = 0.25;
    public const W_SOURCE   = 0.15;
    public const W_QUALITY  = 0.15;
    public const W_EXPLORE  = 0.05;

    /* ---- cold weights: no personal history yet (docs §7) ---- */
    public const COLD_RECENCY  = 0.55;
    public const COLD_QUALITY  = 0.30;
    public const COLD_TRENDING = 0.15;

    /** Recency half-life in hours. The score halves every H hours. */
    public const HALF_LIFE_HOURS = 36.0;

    /**
     * Impressions before a user is treated as warm.
     *
     * A hard switch, not a ramp. Blending two formulas would double the
     * number of states to reason about for a difference nobody can perceive.
     */
    public const COLD_START_IMPRESSIONS = 50;

    /** Fewer than this many impressions from a source earns the explore bonus. */
    public const EXPLORE_IMPRESSIONS = 5;

    /**
     * A tap shorter than this is NOT a success. See docs §3.1.
     *
     * The single most important constant in the file. Count three-second
     * bounces as wins and the feed learns to find better bait.
     */
    public const GOOD_TAP_MS = 10000;

    /** Below this, an excerpt is a stub rather than a summary. */
    public const EXCERPT_MIN_CHARS = 80;

    /** Multiplier applied to a source the user is shown but never opens. */
    public const FATIGUE_PENALTY = 0.3;

    /**
     * Weights are floored here, never zeroed.
     *
     * -log(u)/0 is INF, which sorts last and makes the article unreachable
     * rather than unlikely. A floor keeps "probably not interesting" distinct
     * from "banned" — explicit negative feedback is a hard filter in SQL
     * instead (FeedService::queryCandidates).
     */
    public const WEIGHT_FLOOR = 0.01;

    /**
     * Exponential time decay.
     *
     *     Recency = 0.5 ^ (age_hours / H)
     *
     * Chosen over 1/(1+age) because one number, H, controls it and that
     * number has a plain-English meaning. An undated article decays to
     * effectively zero, which is the "sink, do not float" rule from
     * FeedService::UNDATED_AGE_DAYS.
     */
    public static function recency(?string $publishedAt, ?int $now = null): float
    {
        $now ??= time();

        if ($publishedAt === null) {
            return 0.0;
        }

        $ts = strtotime($publishedAt);
        if ($ts === false) {
            return 0.0;
        }

        // A clock-skewed future article is treated as brand new, not as
        // negatively aged — a negative exponent would score above 1.0 and
        // outrank everything.
        $ageHours = max(0.0, ($now - $ts) / 3600);

        return 2 ** (-$ageHours / self::HALF_LIFE_HOURS);
    }

    /**
     * Article quality, available before any user has done anything.
     *
     *     Quality = 0.6·SourceQuality + 0.2·HasImage + 0.2·HasRealExcerpt
     *
     * The image term is not cosmetic: an article with no image renders as a
     * compact text row rather than a hero card, so it genuinely competes for
     * less attention (see ArticleCard.js).
     */
    public static function quality(float $sourceQualityNorm, bool $hasImage, bool $hasRealExcerpt): float
    {
        return 0.6 * self::clamp01($sourceQualityNorm)
             + 0.2 * ($hasImage ? 1.0 : 0.0)
             + 0.2 * ($hasRealExcerpt ? 1.0 : 0.0);
    }

    /** Smoothed rate: (hits + m·prior) / (trials + m). Bayesian, one line. */
    public static function smoothedRate(int $goodTaps, int $impressions, float $globalRate, int $m): float
    {
        $denominator = $impressions + $m;
        if ($denominator <= 0) {
            return $globalRate;
        }
        return ($goodTaps + $m * $globalRate) / $denominator;
    }

    /** True when this source is new enough to the user to deserve a look. */
    public static function explore(int $impressionsFromSource): float
    {
        return $impressionsFromSource < self::EXPLORE_IMPRESSIONS ? 1.0 : 0.0;
    }

    /**
     * The multiplicative penalty (docs §5.6).
     *
     * Only fatigue is handled here. hide_source and not_interested are hard
     * filters in the candidate query — a user who explicitly said no should
     * get zero, not "rarely", and the floor above would otherwise resurrect
     * them.
     */
    public static function penalty(bool $isFatigued): float
    {
        return $isFatigued ? self::FATIGUE_PENALTY : 1.0;
    }

    /**
     * The warm score. All five terms, weights summing to 1.
     *
     * @param array{recency: float, category: float, source: float, quality: float, explore: float} $t
     */
    public static function warmScore(array $t): float
    {
        return self::W_RECENCY  * self::clamp01($t['recency'])
             + self::W_CATEGORY * self::clamp01($t['category'])
             + self::W_SOURCE   * self::clamp01($t['source'])
             + self::W_QUALITY  * self::clamp01($t['quality'])
             + self::W_EXPLORE  * self::clamp01($t['explore']);
    }

    /**
     * The cold score. No personal terms exist yet, so recency and quality
     * carry the weight and trending stands in for "what others are reading".
     *
     * @param array{recency: float, quality: float, trending: float} $t
     */
    public static function coldScore(array $t): float
    {
        return self::COLD_RECENCY  * self::clamp01($t['recency'])
             + self::COLD_QUALITY  * self::clamp01($t['quality'])
             + self::COLD_TRENDING * self::clamp01($t['trending']);
    }

    /** Score x penalty, floored. This is what the shuffle consumes. */
    public static function finalWeight(float $score, float $penalty): float
    {
        return max($score * $penalty, self::WEIGHT_FLOOR);
    }

    /**
     * Why is this article here?
     *
     * Design constraint 5 in the doc is explainability, and a formula you
     * cannot inspect is one you cannot debug when a user complains. Returns
     * each term's contribution, largest first.
     *
     * @return array<string, float>
     */
    public static function breakdown(array $terms, bool $cold): array
    {
        $parts = $cold
            ? [
                'recency'  => self::COLD_RECENCY  * self::clamp01($terms['recency']),
                'quality'  => self::COLD_QUALITY  * self::clamp01($terms['quality']),
                'trending' => self::COLD_TRENDING * self::clamp01($terms['trending']),
              ]
            : [
                'recency'  => self::W_RECENCY  * self::clamp01($terms['recency']),
                'category' => self::W_CATEGORY * self::clamp01($terms['category']),
                'source'   => self::W_SOURCE   * self::clamp01($terms['source']),
                'quality'  => self::W_QUALITY  * self::clamp01($terms['quality']),
                'explore'  => self::W_EXPLORE  * self::clamp01($terms['explore']),
              ];

        arsort($parts);

        return array_map(static fn(float $v): float => round($v, 4), $parts);
    }

    /**
     * Clamp to 0..1.
     *
     * Defensive rather than decorative: rate_norm is written by the nightly
     * job, and a stale or half-written rollup row must not be able to push a
     * single article's weight above every other article in the feed.
     */
    public static function clamp01(float $v): float
    {
        if (is_nan($v)) {
            return 0.0;
        }
        return max(0.0, min(1.0, $v));
    }
}
