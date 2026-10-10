<?php
/**
 * Shared bits for the sources views: the escaper, a few formatters and the
 * handful of rules the layout does not have (link-buttons, the filter bar,
 * the checkbox grid). Colours are the layout's tokens, so dark mode is free.
 */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

/** '2026-09-01 07:19' from a timestamptz, or a dash. */
$when = static fn($ts): string => $ts === null || $ts === '' ? '—' : substr((string) $ts, 0, 16);

/** The status pill, from the derived status column. */
$pill = static function (string $status, int $fails = 0, int $max = 5): string {
    return match ($status) {
        'deactivated' => '<span class="pill bad">deactivated</span>',
        'failing'     => '<span class="pill warn">failing ' . $fails . '/' . $max . '</span>',
        'no_recent'   => '<span class="pill info">no recent articles</span>',
        default       => '<span class="pill ok">healthy</span>',
    };
};

if (empty($GLOBALS['fp_sources_css'])):
    $GLOBALS['fp_sources_css'] = true;
?>
<style>
  a.btn{display:inline-block;font-size:.8rem;font-weight:600;padding:.35rem .75rem;border-radius:8px;
        border:1px solid var(--line);background:var(--card);color:var(--dim);text-decoration:none;line-height:1.5}
  a.btn:hover{border-color:var(--accent);color:var(--accent)}
  a.btn.primary,button.btn.primary{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
  a.btn.primary:hover,button.btn.primary:hover{color:var(--accent-ink);opacity:.9}
  a.btn.warn:hover,button.btn.danger{border-color:#be123c;color:#be123c}
  .acts{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
  .acts form{margin:0}
  .filters{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem}
  .filters input[type=text]{width:auto;min-width:220px;flex:1;padding:.5rem .75rem}
  .filters select{font:inherit;font-size:.88rem;padding:.5rem .6rem;border:1px solid var(--line);
                  border-radius:10px;background:var(--bg);color:var(--ink)}
  .seg a{display:inline-block;font-size:.76rem;padding:.26rem .65rem;border-radius:7px;color:var(--faint);
         text-decoration:none;white-space:nowrap}
  .seg a.on{background:var(--card);color:var(--ink);font-weight:600;box-shadow:var(--shadow)}
  .seg a b{font-weight:600;color:var(--dim);margin-left:.3rem}
  .kpi.lnk{text-decoration:none;display:block;transition:transform .1s}
  .kpi.lnk:hover{transform:translateY(-2px)}
  .kpi.lnk.sel{outline:2px solid var(--accent);outline-offset:2px}
  .pager{display:flex;align-items:center;gap:.6rem;margin-top:1rem;font-size:.84rem;color:var(--dim)}
  .pager .sp{margin-left:auto}
  .trunc{display:inline-block;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}
  .ck{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.5rem .9rem}
  .ck label{display:flex;align-items:center;gap:.5rem;margin:0;padding:.5rem .6rem;border-radius:9px;
            border:1px solid var(--line);font-weight:500;color:var(--ink);cursor:pointer;font-size:.86rem}
  .ck label:has(input:checked){border-color:var(--accent);background:var(--t-blue)}
  .ck input[type=text]{margin-left:auto;width:48%;padding:.3rem .5rem;font-size:.78rem}
  .err{color:var(--t-pink-ink);font-size:.8rem;margin-top:.3rem}
  .fld input.bad{border-color:#be123c}
  .rank{display:flex;flex-direction:column;gap:.55rem}
  .rank .r{display:flex;align-items:center;gap:.7rem;font-size:.86rem}
  .rank .r i{width:24px;height:24px;border-radius:50%;background:var(--bg);color:var(--dim);display:grid;
             place-items:center;font-style:normal;font-size:.72rem;font-weight:700;flex:none}
  .rank .r:nth-child(-n+3) i{background:var(--accent);color:var(--accent-ink)}
  .rank .r b{margin-left:auto;font-weight:600;color:var(--dim);font-variant-numeric:tabular-nums}
  .kv{display:grid;grid-template-columns:max-content 1fr;gap:.45rem 1rem;font-size:.86rem}
  .kv dt{color:var(--faint);font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;font-weight:600;
         margin:0;padding-top:.15rem}
  .kv dd{margin:0;word-break:break-all}
  .danger{border:1px solid #be123c40}
  .tbl{overflow-x:auto}
  .stack{display:grid;gap:1rem;margin-bottom:1.1rem}
  @media(max-width:700px){.kv{grid-template-columns:1fr}.ck input[type=text]{width:100%;margin-left:0}
                           .ck label{flex-wrap:wrap}}
</style>
<?php endif; ?>
