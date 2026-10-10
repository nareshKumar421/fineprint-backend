<?php
/**
 * Paginator — page/limit/offset from the query string, with a total.
 *
 * Offset pagination, deliberately. The reader feed uses keyset paging
 * because readers scroll thousands deep on a phone; an administrator
 * browsing a table jumps to page 3 and expects "page 3 of 41". OFFSET is
 * the right tool for that, and at 17k rows it is not slow.
 */

declare(strict_types=1);

namespace App\Admin;

final class Paginator
{
    public readonly int $page;
    public readonly int $perPage;
    public readonly int $offset;

    public function __construct(int $perPage = 50, int $maxPerPage = 200)
    {
        $p = $_GET['page'] ?? '1';
        $this->page    = is_string($p) && ctype_digit($p) && (int) $p > 0 ? (int) $p : 1;
        $l = $_GET['limit'] ?? (string) $perPage;
        $want = is_string($l) && ctype_digit($l) ? (int) $l : $perPage;
        $this->perPage = max(10, min($maxPerPage, $want));
        $this->offset  = ($this->page - 1) * $this->perPage;
    }

    /** @return array{page:int, per_page:int, total:int, pages:int, from:int, to:int} */
    public function describe(int $total): array
    {
        $pages = max(1, (int) ceil($total / $this->perPage));
        return [
            'page'     => min($this->page, $pages),
            'per_page' => $this->perPage,
            'total'    => $total,
            'pages'    => $pages,
            'from'     => $total === 0 ? 0 : $this->offset + 1,
            'to'       => min($total, $this->offset + $this->perPage),
        ];
    }

    /** Rebuild the current URL with a different page number, keeping filters. */
    public static function link(int $page): string
    {
        $q = $_GET;
        $q['page'] = $page;
        return '?' . http_build_query($q);
    }
}
