<?php
/**
 * test_admin_layout.php — the dashboard shell, checked as markup.
 *
 * Renders src/Views/admin/layout.php signed out and signed in with stub data
 * and walks the tags with a small stack parser. No database, no network.
 *
 * It exists because of one stray </div>: it closed the content column early,
 * <main> fell out of it, the column collapsed to 0px wide and every page's
 * header painted on top of the breadcrumb — and 58 end-to-end checks passed,
 * because all of them read status codes and markers, none of them structure.
 *
 *     php tests/test_admin_layout.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$pass = 0;
$fail = 0;
function assert_true(bool $cond, string $what): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $what\n"; }
    else       { $fail++; echo "  FAIL $what\n"; }
}

/** Render the layout exactly as App\Admin\Page::render() does. */
function render_layout(array $vars): string
{
    $include = static function (string $file, array $vars): string {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    };
    return $include(__DIR__ . '/../src/Views/admin/layout.php', $vars);
}

/**
 * Walk the tags and return [path-of-<main>, leftovers-on-stack, first-error].
 * Scripts and styles are dropped first: CSS carries "a > b" and an inline SVG
 * data URI, neither of which is markup to the parser.
 */
function walk(string $html): array
{
    $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si', '', $html);
    $html = preg_replace('~<!--.*?-->~s', '', $html);
    $void = ['input', 'meta', 'br', 'hr', 'img', 'link', 'path', 'circle', 'rect', 'line', 'stop', 'polyline'];
    $stack = [];
    $mainPath = null;
    $error = null;
    preg_match_all('~<(/?)([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>~', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $t) {
        $closing = $t[1][0] === '/';
        $name    = strtolower($t[2][0]);
        $attrs   = $t[3][0];
        if (!$closing && (in_array($name, $void, true) || str_ends_with(trim($attrs), '/'))) {
            continue;
        }
        if (!$closing) {
            preg_match('~class="([^"]*)"~', $attrs, $c);
            $stack[] = $name . ($c ? '.' . str_replace(' ', '.', $c[1]) : '');
            if ($name === 'main' && $mainPath === null) {
                $mainPath = implode(' > ', $stack);
            }
            continue;
        }
        $top = $stack === [] ? null : end($stack);
        if ($top === null || !str_starts_with($top, $name)) {
            $error ??= sprintf('</%s> at offset %d but open element is %s', $name, $t[0][1], $top ?? '(none)');
            continue;
        }
        array_pop($stack);
    }
    return [$mainPath, $stack, $error];
}

echo "signed out\n";
$out = render_layout([
    'title' => 'Sign in', 'body' => '<div class="login">LOGIN-BODY</div>',
    'admin' => null, 'active' => '', 'csrf' => 'tok', 'flash' => null,
]);
[$mainPath, $left, $error] = walk($out);
assert_true($error === null, 'no mis-nested close tag' . ($error ? " ($error)" : ''));
assert_true($left === ['html'] || $left === [], 'every element closed (' . implode(' > ', $left) . ')');
assert_true(str_contains($out, 'class="is-login"'), 'login shell');
assert_true(str_contains($out, 'LOGIN-BODY'), 'body rendered');
assert_true(substr_count($out, 'class="iconbtn theme-btn"') === 1, 'one theme toggle');
assert_true(!str_contains($out, 'class="sidebar"'), 'no sidebar before sign-in');

echo "signed in\n";
$out = render_layout([
    'title' => 'Over<view', 'body' => '<div class="head"><h1>PAGE-BODY</h1></div>',
    'admin' => ['name' => 'Naresh', 'email' => 'n@example.test'],
    'active' => 'articles', 'csrf' => 'tok', 'flash' => ['tone' => 'ok', 'text' => 'Saved <b>'],
]);
[$mainPath, $left, $error] = walk($out);
assert_true($error === null, 'no mis-nested close tag' . ($error ? " ($error)" : ''));
assert_true($left === ['html'] || $left === [], 'every element closed (' . implode(' > ', $left) . ')');
assert_true($mainPath === 'html > body > div.shell > div.wrap > main.content',
            "main lives inside the content column ($mainPath)");
assert_true(preg_match('~<header class="topbar">.*?</header>\s*<main class="content">~s', $out) === 1,
            'topbar is immediately followed by main inside .wrap');
assert_true(preg_match('~<div class="me">.*?theme-btn.*?class="who".*?class="signout".*?</div>\s*</header>~s', $out) === 1,
            'right-hand cluster is toggle, identity, sign-out — and closes before </header>');
assert_true(str_contains($out, '<title>Over&lt;view — FinePrint</title>'), 'title escaped');
assert_true(str_contains($out, 'Admin / <b>Over&lt;view</b>'), 'breadcrumb escaped');
assert_true(str_contains($out, 'Saved &lt;b&gt;'), 'flash text escaped');
assert_true(str_contains($out, 'class="avatar">NA<'), 'initials from the display name');
assert_true(preg_match('~class="nav-i on" href="/articles"~', $out) === 1, 'active nav item marked');
assert_true(substr_count($out, 'class="iconbtn theme-btn"') === 1, 'one theme toggle');
assert_true(str_contains($out, 'name="csrf" value="tok"'), 'sign-out form carries the CSRF token');

echo "theme\n";
assert_true(preg_match('~:root\{[^}]*color-scheme:light~s', $out) === 1, 'light is the default');
assert_true(preg_match('~:root\[data-theme="dark"\]\{[^}]*color-scheme:dark~s', $out) === 1, 'dark is opt-in by attribute');
assert_true(!str_contains($out, 'prefers-color-scheme'), 'OS preference is not consulted');
assert_true(substr_count($out, "localStorage.getItem('fp_theme')") === 1, 'saved choice applied in <head>');
assert_true(substr_count($out, "localStorage.setItem('fp_theme'") === 1, 'toggle persists the choice');
assert_true(strpos($out, "getItem('fp_theme')") < strpos($out, '<style>'), 'applied before the stylesheet, so no flash');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
