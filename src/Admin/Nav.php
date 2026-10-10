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
            ['slug' => 'jobs',       'label' => 'Jobs',       'group' => 'Monitor',
             'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
            ['slug' => 'articles',   'label' => 'Articles',   'group' => 'Content',
             'icon' => '<path d="M4 4h16v16H4z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>'],
            ['slug' => 'sources',    'label' => 'Sources',    'group' => 'Content',
             'icon' => '<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5"/>'],
            ['slug' => 'categories', 'label' => 'Categories', 'group' => 'Content',
             'icon' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'],
            ['slug' => 'users',      'label' => 'Users',      'group' => 'People',
             'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
            ['slug' => 'settings',   'label' => 'Settings',   'group' => 'System',
             'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'],
            ['slug' => 'audit',      'label' => 'Audit log',  'group' => 'System',
             'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>'],
            ['slug' => 'system',     'label' => 'System',     'group' => 'System',
             'icon' => '<rect x="2" y="4" width="20" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/>'],
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
