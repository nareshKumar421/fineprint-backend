<?php
/**
 * Nav — the sidebar, as data.
 *
 * One list, rendered by the layout, so adding a module is one entry here
 * rather than an edit to a template that every page shares. `icon` is the
 * path data of a 24x24 stroke icon; the layout wraps it in the <svg>.
 */

declare(strict_types=1);

namespace App\Admin;

final class Nav
{
    /** @return array<int, array{slug:string, label:string, icon:string, group?:string}> */
    public static function items(): array
    {
        return [
            ['slug' => '',           'label' => 'Overview',   'group' => 'Monitor',
             'icon' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],
            ['slug' => 'sync',       'label' => 'Sync',       'group' => 'Monitor',
             'icon' => '<path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>'],
            ['slug' => 'sources',    'label' => 'Sources',    'group' => 'Content',
             'icon' => '<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5"/>'],
        ];
    }

    /** The entry whose slug matches the active page, for the title. */
    public static function label(string $active): string
    {
        foreach (self::items() as $it) {
            if ($it['slug'] === $active) {
                return $it['label'];
            }
        }
        return 'FinePrint';
    }
}
