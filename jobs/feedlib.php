<?php
/**
 * feedlib.php — shared feed download and parsing.
 *
 * Used by BOTH find_feed.php and fetch_feeds.php. They must agree on what
 * counts as a usable feed: if find_feed.php accepts a URL that fetch_feeds.php
 * later treats as broken, the team adds sources that silently never work.
 *
 * Replaces the Python originals' `requests` + `feedparser` with cURL +
 * SimpleXML. No Composer dependencies — everything here is PHP core.
 */

declare(strict_types=1);

const FEED_EXCERPT_DEFAULT = 200;
const FEED_TIMEOUT_DEFAULT = 15;

/* ------------------------------------------------------------------ */
/*  Environment                                                        */
/* ------------------------------------------------------------------ */

/**
 * Load backend/.env into the environment.
 *
 * Cron does not read your shell profile, so without this the jobs fail at the
 * first getenv() with a confusing "cannot connect to database" — and only in
 * production. See docs/08 §4.
 */
function feed_load_env(?string $path = null): void
{
    $path ??= dirname(__DIR__) . '/.env';
    if (!is_readable($path)) {
        return;                      // real env vars may already be set
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip one layer of matching quotes; leave the contents alone.
        $len = strlen($value);
        if ($len >= 2
            && (($value[0] === '"' && $value[$len - 1] === '"')
             || ($value[0] === "'" && $value[$len - 1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {   // a real env var always wins
            putenv("$key=$value");
        }
    }
}

function feed_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function feed_user_agent(): string
{
    // Never send a blank User-Agent — some hosts return 403 with no
    // explanation, and the feed looks dead for no visible reason.
    return feed_env('FEED_USER_AGENT', 'BlogFeedApp/1.0 (+https://yourdomain.com/about)');
}

/* ------------------------------------------------------------------ */
/*  Download                                                           */
/* ------------------------------------------------------------------ */

/**
 * Fetch a URL, following redirects.
 *
 * Returns ['body' => string, 'final_url' => string, 'content_type' => string].
 * Throws RuntimeException on any network or HTTP problem, so the caller can
 * record a failure.
 *
 * final_url matters: wordpress.com/blog/feed/ resolves to /feed. Storing the
 * resolved URL skips a wasted hop on every future run. Without redirect
 * following at all, both of the client's verified-working example feeds look
 * dead. See docs/05 §3.3.
 */
function feed_fetch(string $url, ?int $timeout = null): array
{
    $timeout ??= (int) feed_env('FEED_HTTP_TIMEOUT', (string) FEED_TIMEOUT_DEFAULT);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => feed_user_agent(),
        CURLOPT_ENCODING       => '',      // accept gzip
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body   = curl_exec($ch);
    $err    = curl_error($ch);
    $status = (int)    curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    if ($body === false || $err !== '') {
        throw new RuntimeException("network error: {$err}");
    }
    if ($status !== 200) {
        throw new RuntimeException("http {$status}");
    }
    if (trim((string) $body) === '') {
        throw new RuntimeException('empty response');
    }

    return [
        'body'         => (string) $body,
        'final_url'    => $final !== '' ? $final : $url,
        'content_type' => $ctype,
    ];
}

/* ------------------------------------------------------------------ */
/*  Text cleaning                                                      */
/* ------------------------------------------------------------------ */

/**
 * Strip HTML, collapse whitespace, truncate on a word boundary.
 *
 * Uses mb_* throughout: cutting a UTF-8 multi-byte character in half produces
 * a string that later fails to encode as JSON, which surfaces as an empty feed
 * response and takes an hour to trace.
 *
 * Truncation is not politeness — some feeds carry the FULL article text, and
 * storing it is the copyright problem the whole licensing position avoids.
 */
function clean_excerpt(string $html, ?int $limit = null): string
{
    $limit ??= (int) feed_env('FEED_EXCERPT_CHARS', (string) FEED_EXCERPT_DEFAULT);

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));

    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    $cut   = mb_substr($text, 0, $limit, 'UTF-8');
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($space !== false && $space > $limit * 0.6) {
        $cut = mb_substr($cut, 0, $space, 'UTF-8');   // do not cut mid-word
    }

    return rtrim($cut, " .,;:") . '...';
}

/* ------------------------------------------------------------------ */
/*  Parsing                                                            */
/* ------------------------------------------------------------------ */

/** Pull an image URL out of an entry, or null. Must never throw. */
function feed_extract_image(SimpleXMLElement $node, array $ns, string $body): ?string
{
    try {
        // <media:content url="..."> and <media:thumbnail url="...">
        //
        // Note ->attributes() with no argument. children($ns) scopes ATTRIBUTE
        // lookups to that namespace as well as element lookups, but `url` here
        // is unprefixed and so lives in no namespace. Writing the natural
        // $media->content['url'] silently returns empty and every thumbnail is
        // quietly lost — with no error anywhere.
        if (isset($ns['media'])) {
            $media = $node->children($ns['media']);
            foreach (['content', 'thumbnail'] as $tag) {
                if (isset($media->{$tag})) {
                    $u = trim((string) ($media->{$tag}->attributes()['url'] ?? ''));
                    if ($u !== '') {
                        return $u;
                    }
                }
            }
        }

        // <enclosure url="..." type="image/*">
        if (isset($node->enclosure['url'])) {
            $type = strtolower((string) ($node->enclosure['type'] ?? ''));
            if ($type === '' || str_starts_with($type, 'image/')) {
                $u = trim((string) $node->enclosure['url']);
                if ($u !== '') {
                    return $u;
                }
            }
        }

        // First <img src="..."> in the body HTML.
        if ($body !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $m)) {
            return trim($m[1]) !== '' ? trim($m[1]) : null;
        }
    } catch (Throwable) {
        // A missing thumbnail is a NULL, never a failed feed.
    }

    return null;
}

/** Author from <dc:creator>, falling back to <author>. */
function feed_extract_author(SimpleXMLElement $node, array $ns): ?string
{
    if (isset($ns['dc'])) {
        $dc = $node->children($ns['dc']);
        if (isset($dc->creator)) {
            $a = trim((string) $dc->creator);
            if ($a !== '') {
                return mb_substr($a, 0, 200, 'UTF-8');
            }
        }
    }

    if (isset($node->author)) {
        // Atom nests it: <author><name>...</name></author>
        $a = isset($node->author->name)
            ? trim((string) $node->author->name)
            : trim((string) $node->author);
        if ($a !== '') {
            return mb_substr($a, 0, 200, 'UTF-8');
        }
    }

    return null;
}

/** Convert a feed date string to a UTC 'Y-m-d H:i:s', or null. */
function feed_parse_date(string $raw): ?string
{
    if (trim($raw) === '') {
        return null;
    }

    $raw = trim($raw);

    // Strip a leading RFC 2822 weekday ("Mon, ") before parsing.
    //
    // The weekday is redundant — the date itself is authoritative — and feeds
    // get it wrong. PHP does not ignore the mismatch: given "Mon, 28 Jul 2026"
    // when the 28th is a Tuesday, strtotime() returns 3 August, silently
    // moving the article up to six days into the future. Combined with the
    // future-clamp below, a months-old post can land at "now" and dominate
    // every feed. Dropping the weekday makes the date unambiguous.
    $raw = preg_replace('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,\s*/i', '', $raw) ?? $raw;

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    // Some CMSs mis-set the date far in the future. Left alone, one bad post
    // pins itself to the top of every feed permanently.
    $now = time();
    if ($ts > $now + 86400) {
        $ts = $now;
    }

    return gmdate('Y-m-d H:i:s', $ts);   // gmdate is what makes it UTC
}

/**
 * Parse an RSS 2.0 / RSS 1.0 (RDF) / Atom document.
 *
 * Returns:
 *   [
 *     'title'     => feed title,
 *     'link'      => site link,
 *     'items'     => [ ['guid','title','url','excerpt','published_at',
 *                       'image_url','author','body_length'], ... ],
 *     'malformed' => bool   // recoverable libxml warnings
 *   ]
 *
 * Throws RuntimeException when the document is not XML, has no item nodes,
 * or — critically — parses cleanly but yields ZERO usable articles.
 *
 * That last case is the single most important rule in the specification.
 * If an empty result counted as success, failure_count would reset to 0 on
 * every run: a permanently broken blog would never reach 5 consecutive
 * failures, never be deactivated, never alert anyone, and would show in the
 * logs as a healthy run that found "0 new articles". See docs/05 §2.
 */
function feed_parse(string $xmlString): array
{
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml       = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
    $libErrors = libxml_get_errors();

    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($xml === false) {
        throw new RuntimeException('not valid xml');
    }

    $ns = $xml->getNamespaces(true);

    if (isset($xml->channel->item)) {            // RSS 2.0 — what WordPress uses
        $nodes     = $xml->channel->item;
        $feedTitle = trim((string) ($xml->channel->title ?? ''));
        $feedLink  = trim((string) ($xml->channel->link  ?? ''));
    } elseif (isset($xml->item)) {               // RSS 1.0 / RDF
        $nodes     = $xml->item;
        $feedTitle = trim((string) ($xml->channel->title ?? ''));
        $feedLink  = trim((string) ($xml->channel->link  ?? ''));
    } elseif (isset($xml->entry)) {              // Atom
        $nodes     = $xml->entry;
        $feedTitle = trim((string) ($xml->title ?? ''));
        $feedLink  = isset($xml->link['href']) ? trim((string) $xml->link['href']) : '';
    } else {
        throw new RuntimeException('no items in feed');
    }

    $items = [];

    foreach ($nodes as $node) {
        $title = trim((string) $node->title);

        $link = trim((string) $node->link);
        if ($link === '' && isset($node->link['href'])) {
            // Atom puts the link in an attribute. Miss this and every Atom
            // feed yields zero usable items.
            $link = trim((string) $node->link['href']);
        }

        // A row with no title or no link is useless — skip it.
        if ($title === '' || $link === '') {
            continue;
        }

        $body = (string) ($node->description ?? '');
        if ($body === '' && isset($node->summary)) {
            $body = (string) $node->summary;
        }
        if ($body === '' && isset($ns['content'])) {
            $c = $node->children($ns['content']);
            if (isset($c->encoded)) {
                $body = (string) $c->encoded;      // <content:encoded> full text
            }
        }
        if ($body === '' && isset($node->content)) {
            $body = (string) $node->content;
        }

        $rawDate = (string) ($node->pubDate ?? $node->published ?? $node->updated ?? '');

        $guid = trim((string) ($node->guid ?? $node->id ?? ''));
        if ($guid === '') {
            $guid = $link;                         // fall back to the URL
        }

        $items[] = [
            'guid'         => $guid,
            'title'        => $title,
            'url'          => $link,
            'excerpt'      => clean_excerpt($body),
            'published_at' => feed_parse_date($rawDate),
            'image_url'    => feed_extract_image($node, $ns, $body),
            'author'       => feed_extract_author($node, $ns),
            'body_length'  => mb_strlen(strip_tags($body), 'UTF-8'),
        ];
    }

    // THE critical rule. A feed can be perfectly valid XML and still contain
    // nothing usable — that is a failure, not a quiet success.
    if ($items === []) {
        throw new RuntimeException('feed parsed but contained no usable articles');
    }

    return [
        'title'     => $feedTitle,
        'link'      => $feedLink,
        'items'     => $items,
        'malformed' => $libErrors !== [],
    ];
}
