<?php
/**
 * test_feedlib.php — unit tests for the shared feed parser.
 *
 * Run:  php backend/tests/test_feedlib.php
 *
 * No database and no network. Every case is a fixture string, so these run
 * in milliseconds and can be run before every commit.
 *
 * The case that matters most is "valid XML, zero usable items must FAIL".
 * See docs/05 §2.
 */

declare(strict_types=1);

require __DIR__ . '/../jobs/feedlib.php';

$pass = 0;
$fail = 0;

function ok(string $m): void   { global $pass; $pass++; printf("  \033[32m[PASS]\033[0m %s\n", $m); }
function bad(string $m): void  { global $fail; $fail++; printf("  \033[31m[FAIL]\033[0m %s\n", $m); }

function assert_throws(string $desc, string $xml, string $expectFragment = ''): void
{
    try {
        feed_parse($xml);
        bad("$desc — parsed successfully, should have thrown");
    } catch (RuntimeException $e) {
        if ($expectFragment !== '' && !str_contains($e->getMessage(), $expectFragment)) {
            bad("$desc — threw '{$e->getMessage()}', expected to contain '{$expectFragment}'");
            return;
        }
        ok("$desc — threw: " . $e->getMessage());
    }
}

function assert_eq(string $desc, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        ok("$desc (got " . var_export($actual, true) . ")");
    } else {
        bad("$desc — expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

/* ------------------------------------------------------------------ */

echo "\nfeedlib — parser unit tests\n";
echo "===========================\n";

echo "\nHappy paths\n";

$rss2 = <<<XML
<?xml version="1.0"?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Daily Wellness</title>
    <link>https://dailywellness.example</link>
    <item>
      <title>Ten Ways to Cook Rice</title>
      <link>https://dailywellness.example/rice</link>
      <guid>https://dailywellness.example/?p=1</guid>
      <pubDate>Mon, 28 Jul 2026 03:44:00 +0000</pubDate>
      <dc:creator>Priya</dc:creator>
      <description>Rice is deceptively simple. Here are ten methods.</description>
      <media:content url="https://dailywellness.example/rice.jpg"/>
    </item>
  </channel>
</rss>
XML;

$r = feed_parse($rss2);
assert_eq('RSS 2.0 parses', 1, count($r['items']));
assert_eq('  feed title',      'Daily Wellness', $r['title']);
assert_eq('  item title',      'Ten Ways to Cook Rice', $r['items'][0]['title']);
assert_eq('  guid',            'https://dailywellness.example/?p=1', $r['items'][0]['guid']);
assert_eq('  date is UTC',     '2026-07-28 03:44:00', $r['items'][0]['published_at']);
assert_eq('  dc:creator read', 'Priya', $r['items'][0]['author']);
assert_eq('  media:content image', 'https://dailywellness.example/rice.jpg', $r['items'][0]['image_url']);

$atom = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom Example</title>
  <link href="https://atom.example/"/>
  <entry>
    <title>An Atom Post</title>
    <link href="https://atom.example/post-1"/>
    <id>urn:uuid:1234</id>
    <published>2026-07-30T10:00:00+05:30</published>
    <author><name>Ravi</name></author>
    <summary>A short summary.</summary>
  </entry>
</feed>
XML;

$a = feed_parse($atom);
assert_eq('Atom parses', 1, count($a['items']));
assert_eq('  link read from href attribute', 'https://atom.example/post-1', $a['items'][0]['url']);
assert_eq('  +0530 converted to UTC',        '2026-07-30 04:30:00', $a['items'][0]['published_at']);
assert_eq('  nested author/name read',       'Ravi', $a['items'][0]['author']);

$rdf = <<<XML
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns="http://purl.org/rss/1.0/">
  <channel><title>RDF Example</title><link>https://rdf.example</link></channel>
  <item>
    <title>An RDF Post</title>
    <link>https://rdf.example/1</link>
    <description>Body text.</description>
  </item>
</rdf:RDF>
XML;

$d = feed_parse($rdf);
assert_eq('RSS 1.0 / RDF parses', 1, count($d['items']));

echo "\nFailure paths — these MUST throw\n";

assert_throws('valid XML but ZERO usable items',
    '<?xml version="1.0"?><rss version="2.0"><channel><title>Empty</title>'
    . '<item><description>no title, no link</description></item></channel></rss>',
    'no usable articles');

assert_throws('empty channel, no item nodes at all',
    '<?xml version="1.0"?><rss version="2.0"><channel><title>Empty</title></channel></rss>',
    'no items');

assert_throws('malformed / truncated XML',
    '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Cut off',
    'not valid xml');

assert_throws('an HTML error page served with status 200',
    '<!DOCTYPE html><html><head><title>404 Not Found</title></head>'
    . '<body><h1>Not found</h1></body></html>');

assert_throws('completely empty body', '');

assert_throws('JSON, not XML', '{"articles": []}');

echo "\nPartial data — skip the bad item, keep the good\n";

$mixed = <<<XML
<?xml version="1.0"?>
<rss version="2.0"><channel><title>Mixed</title>
  <item><title>Has no link</title><description>x</description></item>
  <item><link>https://m.example/2</link><description>has no title</description></item>
  <item><title>Good One</title><link>https://m.example/3</link></item>
</channel></rss>
XML;

$m = feed_parse($mixed);
assert_eq('2 unusable items skipped, 1 kept', 1, count($m['items']));
assert_eq('  the kept one is the good one', 'Good One', $m['items'][0]['title']);

echo "\nDates\n";

$nodate = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
        . '<item><title>Undated</title><link>https://n.example/1</link></item></channel></rss>';
assert_eq('missing date becomes NULL, item still kept',
    null, feed_parse($nodate)['items'][0]['published_at']);

$garbage = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
         . '<item><title>X</title><link>https://n.example/2</link>'
         . '<pubDate>not a date at all</pubDate></item></channel></rss>';
assert_eq('unparseable date becomes NULL',
    null, feed_parse($garbage)['items'][0]['published_at']);

$future = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
        . '<item><title>X</title><link>https://n.example/3</link>'
        . '<pubDate>Wed, 01 Jan 2098 00:00:00 +0000</pubDate></item></channel></rss>';
$futureDate = feed_parse($future)['items'][0]['published_at'];
if ($futureDate !== null && strtotime($futureDate) <= time() + 86400) {
    ok("far-future date is clamped to now (got $futureDate)");
} else {
    bad("far-future date NOT clamped — one bad post would pin itself to the top of every feed (got $futureDate)");
}

$wrongDay = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
          . '<item><title>X</title><link>https://n.example/4</link>'
          . '<pubDate>Mon, 28 Jul 2026 03:44:00 +0000</pubDate></item></channel></rss>';
// 28 Jul 2026 is a TUESDAY. PHP shifts a mismatched weekday forward to the
// next Monday (3 Aug), moving the article up to six days into the future.
assert_eq('wrong weekday name does NOT shift the date',
    '2026-07-28 03:44:00', feed_parse($wrongDay)['items'][0]['published_at']);

echo "\nExcerpts\n";

assert_eq('HTML tags stripped',
    'Hello world', clean_excerpt('<p>Hello <b>world</b></p>'));
assert_eq('entities decoded',
    'Tom & Jerry', clean_excerpt('Tom &amp; Jerry'));
assert_eq('whitespace collapsed',
    'a b c', clean_excerpt("a\n\n  b\t c"));

$long = str_repeat('word ', 200);
$cut  = clean_excerpt($long, 200);
if (mb_strlen($cut, 'UTF-8') <= 203 && str_ends_with($cut, '...')) {
    ok('long text truncated to ~200 chars with ellipsis (got ' . mb_strlen($cut, 'UTF-8') . ')');
} else {
    bad('truncation wrong — length ' . mb_strlen($cut, 'UTF-8') . ': ' . mb_substr($cut, -20, null, 'UTF-8'));
}

// Multi-byte safety: cutting a Devanagari string mid-character produces bytes
// that later fail to encode as JSON, surfacing as an empty feed response.
$hindi = str_repeat('नमस्ते दुनिया ', 40);
$hcut  = clean_excerpt($hindi, 100);
assert_eq('multi-byte text survives truncation as valid UTF-8',
    true, mb_check_encoding($hcut, 'UTF-8'));
assert_eq('  and encodes as JSON',
    true, json_encode(['e' => $hcut]) !== false);

echo "\nFull-text detection (licensing)\n";

$fulltext = '<?xml version="1.0"?>'
    . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">'
    . '<channel><title>T</title><item><title>X</title><link>https://f.example/1</link>'
    . '<content:encoded>' . str_repeat('This is the entire article body. ', 60) . '</content:encoded>'
    . '</item></channel></rss>';
$f = feed_parse($fulltext);
if ($f['items'][0]['body_length'] > 1000) {
    ok('full article text is detected (body_length ' . $f['items'][0]['body_length'] . ')');
} else {
    bad('full text not detected — body_length ' . $f['items'][0]['body_length']);
}
if (mb_strlen($f['items'][0]['excerpt'], 'UTF-8') <= 203) {
    ok('  and the stored excerpt is still truncated, never the full body');
} else {
    bad('  excerpt is too long — full article text would be stored');
}

echo "\nImage extraction fallbacks\n";

$enc = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
     . '<item><title>X</title><link>https://i.example/1</link>'
     . '<enclosure url="https://i.example/pic.jpg" type="image/jpeg"/></item></channel></rss>';
assert_eq('enclosure image', 'https://i.example/pic.jpg', feed_parse($enc)['items'][0]['image_url']);

$inline = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
        . '<item><title>X</title><link>https://i.example/2</link>'
        . '<description><![CDATA[<img src="https://i.example/inline.png"> text]]></description>'
        . '</item></channel></rss>';
assert_eq('inline <img> fallback', 'https://i.example/inline.png', feed_parse($inline)['items'][0]['image_url']);

$noimg = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
       . '<item><title>X</title><link>https://i.example/3</link>'
       . '<description>no image here</description></item></channel></rss>';
assert_eq('no image is NULL, not an error', null, feed_parse($noimg)['items'][0]['image_url']);

/* ------------------------------------------------------------------ */

echo "\n---------------------------\n";
printf("passed: \033[32m%d\033[0m   failed: \033[31m%d\033[0m\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
