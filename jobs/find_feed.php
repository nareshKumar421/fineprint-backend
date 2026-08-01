<?php
/**
 * find_feed.php — discover and validate a blog's feed URL before adding it.
 *
 * PHP replacement for the specification's find_feed.py. No Python is used
 * anywhere in this project, and this tool had no PHP version, so it is
 * written from scratch. Uses DOMDocument in place of BeautifulSoup.
 *
 * Usage:
 *     php find_feed.php https://dailywellness.com
 *     php find_feed.php https://dailywellness.com --category yoga
 *     php find_feed.php --file blogs.txt
 *     php find_feed.php --file blogs.txt --category food
 *
 * Prints ready-to-paste SQL when it finds a working feed. It does NOT touch
 * the database — adding a blog stays a deliberate human action, and the
 * printed statement can be reviewed before it runs.
 *
 * Exit code: 0 if every URL checked has a working feed, 1 otherwise.
 */

declare(strict_types=1);

require __DIR__ . '/feedlib.php';

feed_load_env();

/**
 * Tried in order, only when the blog declares no feed of its own.
 * Autodiscovery beats every one of these.
 */
const GUESS_PATHS = [
    '/feed/',
    '/feed',
    '/rss/',
    '/?feed=rss2',
    '/atom.xml',
    '/rss.xml',
    '/index.xml',
    '/feeds/posts/default',   // Blogger
];

/* ------------------------------------------------------------------ */
/*  Output helpers                                                     */
/* ------------------------------------------------------------------ */

function c(string $text, string $colour): string
{
    // Only colourise a real terminal; redirected output stays clean.
    if (!stream_isatty(STDOUT)) {
        return $text;
    }
    $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'bold' => 1, 'dim' => 2];
    return "\033[" . ($codes[$colour] ?? 0) . "m{$text}\033[0m";
}

function out(string $line = ''): void
{
    echo $line, "\n";
}

/* ------------------------------------------------------------------ */
/*  Discovery                                                          */
/* ------------------------------------------------------------------ */

function normalise(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return rtrim($url, '/');
}

/**
 * Read the homepage and pull out <link rel="alternate"> feed declarations.
 * This is authoritative — it is how the blog itself declares its feed.
 */
function discover_from_html(string $siteUrl): array
{
    try {
        $res = feed_fetch($siteUrl);
    } catch (RuntimeException $e) {
        out('  ' . c('!', 'yellow') . ' could not load homepage: ' . $e->getMessage());
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    // Real-world HTML is messy; DOMDocument complains loudly and recovers fine.
    $doc->loadHTML($res['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($doc);
    $found = [];

    foreach ($xpath->query('//link[@rel]') as $tag) {
        /** @var DOMElement $tag */
        if (!str_contains(strtolower($tag->getAttribute('rel')), 'alternate')) {
            continue;
        }
        $type = strtolower($tag->getAttribute('type'));
        if (!str_contains($type, 'rss') && !str_contains($type, 'atom') && !str_contains($type, 'xml')) {
            continue;
        }
        $href = trim($tag->getAttribute('href'));
        if ($href !== '') {
            $found[] = absolutise($href, $res['final_url']);
        }
    }

    $found = array_values(array_unique($found));

    // WordPress declares its COMMENTS feed in the same <link rel="alternate">
    // block. It is valid RSS and validates perfectly — accept it by mistake
    // and you ingest reader comments as articles. Keep it, but rank it last.
    $articles = array_values(array_filter($found, fn($u) => !str_contains(strtolower($u), 'comment')));
    $comments = array_values(array_filter($found, fn($u) =>  str_contains(strtolower($u), 'comment')));

    return array_merge($articles, $comments);
}

/** Resolve a relative or protocol-relative href against the page URL. */
function absolutise(string $href, string $base): string
{
    if (str_starts_with($href, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }

    $parts  = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host']   ?? '';
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    $root   = "{$scheme}://{$host}{$port}";

    if (str_starts_with($href, '/')) {
        return $root . $href;
    }

    $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
    return $root . $dir . '/' . $href;
}

/**
 * Download and parse a candidate. Returns a summary, or null if unusable.
 *
 * A 200 response is NOT proof of a feed — plenty of sites serve an HTML error
 * page with status 200. feed_parse() confirms real entries came back, and
 * throws when a feed yields zero usable articles.
 */
function validate(string $feedUrl): ?array
{
    try {
        $res    = feed_fetch($feedUrl);
        $parsed = feed_parse($res['body']);
    } catch (RuntimeException) {
        return null;
    }

    $items    = $parsed['items'];
    $hasDates = false;
    foreach ($items as $it) {
        if ($it['published_at'] !== null) {
            $hasDates = true;
            break;
        }
    }

    return [
        'final_url' => $res['final_url'],
        'blog_name' => $parsed['title'] !== '' ? $parsed['title'] : '(no title)',
        'site_url'  => $parsed['link'],
        'count'     => count($items),
        'latest'    => $items[0]['title'],
        'has_dates' => $hasDates,
        'full_text' => $items[0]['body_length'] > 1000,
        'malformed' => $parsed['malformed'],
    ];
}

/* ------------------------------------------------------------------ */
/*  SQL output                                                         */
/* ------------------------------------------------------------------ */

/** Blog titles contain apostrophes ("Sarah's Kitchen"); double them. */
function q(string $s): string
{
    return str_replace("'", "''", $s);
}

function sql_for(array $info, string $siteUrl, ?string $category, string $addedBy): string
{
    $name = q($info['blog_name']);
    $site = q($info['site_url'] !== '' ? $info['site_url'] : $siteUrl);
    $feed = q($info['final_url']);      // the POST-REDIRECT url — what belongs in the column
    $by   = q($addedBy);

    $lines = [
        "INSERT INTO blog_sources (blog_name, blog_url, feed_url, is_active, failure_count, added_by)",
        "VALUES ('{$name}', '{$site}', '{$feed}', true, 0, '{$by}');",
    ];

    if ($category !== null) {
        $cat = q($category);
        $lines[] = '';
        $lines[] = "INSERT INTO category_blog_map (category_id, blog_source_id)";
        $lines[] = "SELECT c.id, b.id";
        $lines[] = "FROM   category_master c, blog_sources b";
        $lines[] = "WHERE  c.slug = '{$cat}' AND b.feed_url = '{$feed}';";
    }

    return implode("\n", $lines);
}

/* ------------------------------------------------------------------ */
/*  One site                                                           */
/* ------------------------------------------------------------------ */

function check(string $siteUrl, ?string $category, string $addedBy): bool
{
    $siteUrl = normalise($siteUrl);

    out();
    out(str_repeat('=', 64));
    out(c($siteUrl, 'bold'));
    out(str_repeat('=', 64));

    $candidates = [];

    out('  reading homepage for autodiscovery tags...');
    foreach (discover_from_html($siteUrl) as $url) {
        if (!in_array($url, $candidates, true)) {
            $candidates[] = $url;
        }
    }

    if ($candidates !== []) {
        out('  declared feed(s): ' . count($candidates));
    } else {
        out('  none declared - falling back to guessing common paths');
    }

    foreach (GUESS_PATHS as $path) {
        $url = $siteUrl . $path;
        if (!in_array($url, $candidates, true)) {
            $candidates[] = $url;
        }
    }

    foreach ($candidates as $url) {
        $info = validate($url);
        if ($info === null) {
            out('  ' . c('x', 'dim') . '  ' . c($url, 'dim'));
            continue;
        }

        out();
        out('  ' . c('OK', 'green') . ' ' . $info['final_url']);
        out('     blog name : ' . $info['blog_name']);
        out('     articles  : ' . $info['count']);
        out('     latest    : ' . mb_substr($info['latest'], 0, 60, 'UTF-8'));

        if (!$info['has_dates']) {
            // Undated articles get a fixed 30-day age in the feed's weighted
            // shuffle, so they sink but never disappear. A blog where EVERY
            // post is undated will barely surface — usually not worth adding.
            out('     ' . c('WARNING   : no publish dates - recency sorting will not work', 'yellow'));
        }
        if ($info['full_text']) {
            out('     ' . c('NOTE      : feed carries full article text - show excerpts only', 'yellow'));
        }
        if ($info['malformed']) {
            out('     NOTE      : XML is slightly malformed but parsed fine');
        }
        if (str_contains(strtolower($info['final_url']), 'comment')) {
            out('     ' . c('WARNING   : this looks like a COMMENTS feed - verify before adding', 'red'));
        }

        out();
        out(sql_for($info, $siteUrl, $category, $addedBy));
        out();
        return true;
    }

    out();
    out('  ' . c('FAILED', 'red') . ' - no working feed found. Check the site manually.');
    return false;
}

/* ------------------------------------------------------------------ */
/*  Main                                                               */
/* ------------------------------------------------------------------ */

function usage(): never
{
    out('Usage:');
    out('  php find_feed.php <url> [--category <slug>] [--added-by <name>]');
    out('  php find_feed.php --file <list.txt> [--category <slug>] [--added-by <name>]');
    exit(2);
}

$args     = array_slice($argv, 1);
$url      = null;
$file     = null;
$category = null;
$addedBy  = get_current_user() ?: 'unknown';

for ($i = 0; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--category': $category = $args[++$i] ?? null; break;
        case '--file':     $file     = $args[++$i] ?? null; break;
        case '--added-by': $addedBy  = $args[++$i] ?? $addedBy; break;
        case '-h':
        case '--help':     usage();
        default:
            if (str_starts_with($args[$i], '-')) {
                out('unknown option: ' . $args[$i]);
                usage();
            }
            $url = $args[$i];
    }
}

if ($file !== null) {
    if (!is_readable($file)) {
        out('cannot read file: ' . $file);
        exit(2);
    }
    $urls = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $urls[] = $line;
        }
    }
} elseif ($url !== null) {
    $urls = [$url];
} else {
    usage();
}

$ok = 0;
foreach ($urls as $u) {
    if (check($u, $category, $addedBy)) {
        $ok++;
    }
}

out();
out(str_repeat('=', 64));
out(sprintf('%d of %d blog(s) have a working feed', $ok, count($urls)));

exit($ok === count($urls) ? 0 : 1);
