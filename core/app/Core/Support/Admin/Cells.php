<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Admin;

use Illuminate\Support\Facades\Blade;

/**
 * Cell markup for the admin list rows (see DataTable), on the owner-ui kit
 * classes (assets/owner-ui/admin.css).
 *
 * The hooks are the contract with the list scripts: `quick-check` feeds the
 * bulk-delete button, `app-reorder-icon` is the row-reorder handle,
 * `data-toggle="slidePanel"` + `data-url` opens the edit side panel.
 * Every text argument is HTML-escaped here; pass trusted HTML only through
 * the explicitly named `$html` parameters.
 */
final class Cells
{
    public static function text(?string $value): string
    {
        return '<td>'.e((string) $value).'</td>';
    }

    /** A bold line with a muted second line underneath. */
    public static function stacked(?string $title, ?string $subtitle): string
    {
        return '<td><strong>'.e((string) $title).'</strong><small class="ui-cell-sub">'.e((string) $subtitle).'</small></td>';
    }

    public static function html(string $html): string
    {
        return '<td>'.$html.'</td>';
    }

    /** A small label; `$tone` is success|warning|danger|info|secondary|primary (mapped to the kit tag tones). */
    public static function badge(string $label, string $tone): string
    {
        $kitTone = match ($tone) {
            'success', 'warning', 'danger' => $tone,
            'info', 'primary' => 'accent',
            default => 'neutral',
        };

        return '<span class="ui-tag" data-tone="'.$kitTone.'">'.e($label).'</span>';
    }

    public static function status(bool $enabled, ?string $on = null, ?string $off = null): string
    {
        return self::html($enabled
            ? self::badge($on ?? ___('Enabled'), 'success')
            : self::badge($off ?? ___('Disabled'), 'danger'));
    }

    /** Thumbnail + label on one line. */
    public static function image(string $src, ?string $label, int $width = 25): string
    {
        return '<td><span class="ui-cell-img"><img src="'.e($src).'" width="'.$width.'" height="'.$width.'" alt="">'.e((string) $label).'</span></td>';
    }

    /** Round avatar with a name and a caption. */
    public static function person(string $avatar, ?string $name, ?string $caption): string
    {
        return '<td><div class="ui-cell-person">'
            .'<img src="'.e($avatar).'" alt="">'
            .'<div><strong>'.e((string) $name).'</strong>'
            .'<small class="ui-cell-sub">'.e((string) $caption).'</small></div>'
            .'</div></td>';
    }

    /** Drag handle for row reordering; the id travels in the row's DT_RowId. */
    public static function reorderHandle(int|string $id): string
    {
        return '<td><span class="ui-reorder app-reorder-icon" title="'.e(___('Reorder')).'">'.self::icon('grip').'</span>'
            .'<span class="ui-visually-hidden">'.e((string) $id).'</span></td>';
    }

    /** Opens the given URL in the admin side panel. */
    public static function editButton(string $url): string
    {
        return self::actions(self::editLink($url));
    }

    /** A cell holding a row of action buttons (see editLink / viewLink). */
    public static function actions(string ...$buttons): string
    {
        return '<td><div class="ui-cell-actions">'.implode('', $buttons).'</div></td>';
    }

    public static function editLink(string $url): string
    {
        return '<button type="button" data-url="'.e($url).'" data-toggle="slidePanel" title="'.e(___('Edit')).'"'
            .' aria-label="'.e(___('Edit')).'" class="ui-icon-btn ui-icon-btn--ghost">'.self::icon('pencil').'</button>';
    }

    /** Opens a public page in a new tab. */
    public static function viewLink(string $url): string
    {
        return '<a href="'.e($url).'" target="_blank" rel="noopener" title="'.e(___('View')).'"'
            .' aria-label="'.e(___('View')).'" class="ui-icon-btn ui-icon-btn--ghost">'.self::icon('eye').'</a>';
    }

    /** A text cell that links somewhere in the admin (e.g. the full edit page). */
    public static function link(string $url, ?string $label): string
    {
        return '<td><a href="'.e($url).'">'.e((string) $label).'</a></td>';
    }

    /**
     * An icon button that navigates to `$url` (same tab). `$icon` is a kit /
     * Tabler icon name; the older `icon-feather-*` names are still understood.
     */
    public static function iconLink(string $url, string $icon, string $title): string
    {
        return '<a href="'.e($url).'" title="'.e($title).'" aria-label="'.e($title).'"'
            .' class="ui-icon-btn ui-icon-btn--ghost">'.self::icon($icon).'</a>';
    }

    /** Row selector for the bulk-delete action. */
    public static function checkbox(int|string $id): string
    {
        $id = e((string) $id);

        return '<td><input type="checkbox" id="check_'.$id.'" value="'.$id.'" class="quick-check"'
            .' aria-label="'.e(___('Select')).'"></td>';
    }

    /** Inline SVG for a kit / Tabler icon name (rendered once per name and request). */
    public static function icon(string $name): string
    {
        static $svgs = [];
        $name = self::FEATHER[$name] ?? $name;

        return $svgs[$name] ??= trim(Blade::render('<x-ui.icon :name="$name" size="18" />', ['name' => $name]));
    }

    /** Older icon-font names callers may still pass → kit / Tabler names. */
    private const FEATHER = [
        'icon-feather-edit' => 'pencil',
        'icon-feather-eye' => 'eye',
        'icon-feather-message-square' => 'chat',
        'icon-feather-trash' => 'trash',
        'icon-feather-trash-2' => 'trash',
        'icon-feather-menu' => 'grip',
        'icon-feather-external-link' => 'external',
        'icon-feather-copy' => 'copy',
        'icon-feather-download' => 'download',
        'icon-feather-settings' => 'settings',
        'icon-feather-users' => 'users',
        'icon-feather-user' => 'user',
    ];
}
