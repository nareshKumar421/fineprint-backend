<?php
/**
 * Chart primitives — inline SVG, no library.
 *
 * The project forbids runtime dependencies, so there is no Chart.js here and
 * no build step. These draw straight into the page, which also means they
 * render with the data already fetched: no second request, no loading
 * spinner, and the page is complete in one round trip to a database that is
 * a few milliseconds away.
 *
 * Every function takes [label => value] and is defensive about an empty set,
 * because a dashboard renders on day one when several of these are empty.
 */

/** Colour ramp shared by every chart, so a category keeps its colour. */
function fp_palette(): array
{
    return ['#2563eb', '#38bdf8', '#34d399', '#fbbf24', '#f472b6',
            '#a78bfa', '#fb923c', '#22d3ee', '#94a3b8', '#f87171'];
}

function fp_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

/**
 * Vertical bars with a baseline. Used for daily ingestion.
 *
 * Labels are thinned rather than rotated — rotated tick labels are the first
 * thing to become unreadable on a phone, and every other day is enough to
 * read a two-week trend.
 */
function fp_bars(array $data, int $h = 190, string $colour = '#2563eb'): string
{
    if ($data === []) {
        return '<p class="muted" style="padding:2rem 0;text-align:center">No data yet.</p>';
    }

    $max   = max(1, max($data));
    $n     = count($data);
    $gap   = 6;
    $barW  = max(6, (int) round((760 - $gap * ($n - 1)) / $n));
    $w     = $n * $barW + ($n - 1) * $gap;
    $plotH = $h - 26;                       // room for the labels

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
         . '" preserveAspectRatio="none" role="img" aria-label="Daily totals">';

    // Three gridlines. More is noise; none makes the heights unreadable.
    foreach ([0.5, 1.0] as $f) {
        $y = $plotH - $plotH * $f;
        $svg .= '<line x1="0" y1="' . round($y, 1) . '" x2="' . $w . '" y2="' . round($y, 1)
              . '" stroke="currentColor" stroke-opacity=".10" stroke-width="1"/>';
    }

    $i = 0;
    foreach ($data as $label => $value) {
        $bh = max(2, (int) round(($value / $max) * $plotH));
        $x  = $i * ($barW + $gap);
        $y  = $plotH - $bh;

        $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $barW . '" height="' . $bh
              . '" rx="3" fill="' . $colour . '" opacity="' . ($i === $n - 1 ? '1' : '.82') . '">'
              . '<title>' . fp_e((string) $label) . ': ' . number_format($value) . '</title></rect>';

        // Every other label, and always the last.
        if ($n <= 8 || $i % 2 === 0 || $i === $n - 1) {
            $svg .= '<text x="' . ($x + $barW / 2) . '" y="' . ($h - 8)
                  . '" text-anchor="middle" font-size="10" fill="currentColor" fill-opacity=".55">'
                  . fp_e(substr((string) $label, -5)) . '</text>';
        }
        $i++;
    }

    return $svg . '</svg>';
}

/** Smoothed area + line. Used for sync throughput. */
function fp_line(array $data, int $h = 190, string $colour = '#2563eb'): string
{
    if (count($data) < 2) {
        return '<p class="muted" style="padding:2rem 0;text-align:center">Not enough history yet.</p>';
    }

    $values = array_values($data);
    $labels = array_keys($data);
    $max    = max(1, max($values));
    $min    = 0;                              // anchored at zero: this is a count
    $n      = count($values);
    $w      = 760;
    $plotH  = $h - 26;
    $step   = $w / ($n - 1);

    $pts = [];
    foreach ($values as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($plotH - (($v - $min) / ($max - $min)) * $plotH, 1);
        $pts[] = [$x, $y];
    }

    $line = '';
    foreach ($pts as $i => [$x, $y]) {
        $line .= ($i === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
    }
    $area = $line . 'L' . $pts[$n - 1][0] . ' ' . $plotH . ' L0 ' . $plotH . ' Z';

    $id  = 'g' . substr(md5($colour . $n), 0, 6);
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
         . '" preserveAspectRatio="none" role="img" aria-label="Trend">'
         . '<defs><linearGradient id="' . $id . '" x1="0" x2="0" y1="0" y2="1">'
         . '<stop offset="0%" stop-color="' . $colour . '" stop-opacity=".28"/>'
         . '<stop offset="100%" stop-color="' . $colour . '" stop-opacity="0"/>'
         . '</linearGradient></defs>';

    foreach ([0.5, 1.0] as $f) {
        $y = $plotH - $plotH * $f;
        $svg .= '<line x1="0" y1="' . round($y, 1) . '" x2="' . $w . '" y2="' . round($y, 1)
              . '" stroke="currentColor" stroke-opacity=".10"/>';
    }

    $svg .= '<path d="' . $area . '" fill="url(#' . $id . ')"/>'
          . '<path d="' . $line . '" fill="none" stroke="' . $colour
          . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';

    foreach ($pts as $i => [$x, $y]) {
        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . ($i === $n - 1 ? 4 : 2.5)
              . '" fill="' . $colour . '"><title>' . fp_e((string) $labels[$i]) . ': '
              . number_format($values[$i]) . '</title></circle>';
        if ($i % 2 === 0 || $i === $n - 1) {
            $svg .= '<text x="' . $x . '" y="' . ($h - 8) . '" text-anchor="middle" font-size="10"'
                  . ' fill="currentColor" fill-opacity=".55">' . fp_e(substr((string) $labels[$i], -5)) . '</text>';
        }
    }

    return $svg . '</svg>';
}

/**
 * Donut, drawn with stroke-dasharray rather than arc paths.
 *
 * An arc path needs the large-arc flag handled correctly or a segment past
 * 180° silently inverts. A dashed circle cannot get that wrong.
 */
function fp_donut(array $data, int $size = 170): string
{
    $data = array_filter($data, static fn($v) => $v > 0);
    if ($data === []) {
        return '<p class="muted">No data yet.</p>';
    }

    $total  = array_sum($data);
    $r      = $size / 2 - 16;
    $circ   = 2 * M_PI * $r;
    $colors = fp_palette();
    $offset = 0.0;
    $i      = 0;

    $svg = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size
         . '" role="img" aria-label="Distribution">'
         . '<g transform="rotate(-90 ' . ($size / 2) . ' ' . ($size / 2) . ')">';

    foreach ($data as $label => $value) {
        $len  = ($value / $total) * $circ;
        $svg .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r
              . '" fill="none" stroke="' . $colors[$i % count($colors)] . '" stroke-width="22"'
              . ' stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '"'
              . ' stroke-dashoffset="' . round(-$offset, 2) . '">'
              . '<title>' . fp_e((string) $label) . ': ' . number_format($value)
              . ' (' . round($value / $total * 100) . '%)</title></circle>';
        $offset += $len;
        $i++;
    }

    return $svg . '</g></svg>';
}

/** One row of a horizontal bar list. */
function fp_hbar(string $label, int $value, int $max, string $colour, string $suffix = ''): string
{
    $pct = $max > 0 ? max(2, (int) round($value / $max * 100)) : 2;
    return '<div class="hbar">'
         . '<span class="hbar-l">' . fp_e($label) . '</span>'
         . '<span class="hbar-t"><span class="hbar-f" style="width:' . $pct . '%;background:' . $colour . '"></span></span>'
         . '<span class="hbar-v">' . number_format($value) . fp_e($suffix) . '</span>'
         . '</div>';
}

/**
 * Tiny area sparkline for a stat card. No axes, no labels, no gridlines —
 * it shows the shape of the last fortnight, and the number beside it carries
 * the value. A single point (or none) draws a flat baseline rather than
 * nothing, so the card keeps its height on day one.
 */
function fp_spark(array $data, int $w = 120, int $h = 36, string $colour = '#1677ff'): string
{
    $values = array_values(array_map('intval', $data));
    $labels = array_keys($data);
    $n      = count($values);
    $pad    = 2;                                   // keeps the 2px stroke inside the box
    $plotH  = $h - $pad * 2;

    if ($n < 2) {
        $y = $h - $pad;
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
             . '" preserveAspectRatio="none" role="img" aria-label="Trend (not enough history)">'
             . '<line x1="0" y1="' . $y . '" x2="' . $w . '" y2="' . $y . '" stroke="' . $colour
             . '" stroke-width="2" stroke-opacity=".35" stroke-linecap="round"/></svg>';
    }

    $max  = max(1, max($values));
    $step = $w / ($n - 1);
    $pts  = [];
    foreach ($values as $i => $v) {
        $pts[] = [round($i * $step, 1), round($pad + $plotH - ($v / $max) * $plotH, 1)];
    }

    $line = '';
    foreach ($pts as $i => [$x, $y]) {
        $line .= ($i === 0 ? 'M' : 'L') . $x . ' ' . $y . ' ';
    }
    $area = $line . 'L' . $pts[$n - 1][0] . ' ' . $h . ' L0 ' . $h . ' Z';
    $id   = 's' . substr(md5($colour . $w . $h . $n), 0, 6);
    [$lx, $ly] = $pts[$n - 1];

    $title = fp_e((string) $labels[$n - 1]) . ': ' . number_format($values[$n - 1]);

    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
         . '" preserveAspectRatio="none" role="img" aria-label="Trend, last ' . $n . ' points">'
         . '<defs><linearGradient id="' . $id . '" x1="0" x2="0" y1="0" y2="1">'
         . '<stop offset="0%" stop-color="' . $colour . '" stop-opacity=".30"/>'
         . '<stop offset="100%" stop-color="' . $colour . '" stop-opacity=".02"/>'
         . '</linearGradient></defs>'
         . '<path d="' . $area . '" fill="url(#' . $id . ')"/>'
         . '<path d="' . $line . '" fill="none" stroke="' . $colour
         . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
         . '<circle cx="' . $lx . '" cy="' . $ly . '" r="2.5" fill="' . $colour . '"><title>' . $title . '</title></circle>'
         . '</svg>';
}
