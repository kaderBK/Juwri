<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Modules\Content\Models\Page;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Modules\Content\Models\Testimonial;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\ViewServiceProvider as ConcreteViewServiceProvider;
use Juwri\Core\Settings\PlatformSettings;
use Throwable;

/**
 * View globals and view composers.
 *
 * Every view gets `settings`, `activeTheme`, `activeThemeAssets` and `languages`
 * (computed once per request). Once installed, admin requests also get the
 * admin language list, header notifications, sidebar counters and the plan list
 * for the user screen; storefront/marketing requests get the blog sidebar data
 * and the footer page links.
 */
class ViewServiceProvider extends ConcreteViewServiceProvider
{
    public function boot(): void
    {
        // Registered even before install: the marketing, docs and MCP landing pages
        // use the marketing frame, which reads these globals directly.
        $this->shareRequestGlobals();

        // Both sets are registered at boot and each composer decides for ITS request whether it applies (installed,
        // admin or storefront): a long-lived Octane worker boots once and serves admin, storefront and marketing
        // requests, and a site can be installed after the worker started.
        $this->composeAdminViews();
        $this->composeFrontendViews();
    }

    /** Is this request for the admin panel of an installed site? (decided per request, never at boot) */
    private function adminRequest(): bool
    {
        return app_is_installed() && is_admin_url();
    }

    /** Is this request for the storefront / marketing side of an installed site? */
    private function frontendRequest(): bool
    {
        return app_is_installed() && ! is_admin_url();
    }

    /**
     * P1: one wildcard composer fires for every @include/component, so the globals
     * are built on first use and reused for the rest of the request. A `settings`
     * value that a controller or test already shared is never overwritten.
     */
    private function shareRequestGlobals(): void
    {
        $globals = null;
        $stamp = null;

        view()->composer('*', function (View $view) use (&$globals, &$stamp) {
            // once per request: the stamp changes with every request of a long-lived worker (Octane)
            if ($stamp !== \Juwri\Core\Support\RequestEpoch::id()) {
                $globals = null;
                $stamp = \Juwri\Core\Support\RequestEpoch::id();
            }
            $globals ??= [
                // DEC-5: on a reseller host the platform's socials/contact are removed.
                'settings' => brand_scrub_settings(array_to_object($this->settingsForViews())),
                'activeTheme' => active_theme(),
                'activeThemeAssets' => active_theme(true),
                // Language switcher and hreflang on every storefront/marketing view.
                'languages' => $this->quietly(
                    fn () => Language::query()->where('active', 1)->orderBy('position')->get(),
                    collect(),
                ),
            ];

            $view->with(view()->shared('settings') === null ? $globals : array_diff_key($globals, ['settings' => true]));
        });
    }

    /**
     * Site options for display: credentials masked (views only show that one is
     * set; runtime code reads config('settings.*')) and JSON values decoded. Empty
     * when the options table is not there yet.
     */
    /** @return Collection<string, mixed> */
    private function settingsForViews(): Collection
    {
        try {
            return collect(PlatformSettings::masked());
        } catch (Throwable) {
            return collect();
        }
    }

    private function composeAdminViews(): void
    {
        // P1: one languages query per request, not one per view.
        $languages = null;
        $stamp = null;
        view()->composer('*', function (View $view) use (&$languages, &$stamp) {
            if (! $this->adminRequest()) {
                return;
            }
            if ($stamp !== \Juwri\Core\Support\RequestEpoch::id()) {
                $languages = null;
                $stamp = \Juwri\Core\Support\RequestEpoch::id();
            }
            $languages ??= Language::query()->where('active', 1)->orderBy('position')->get();
            $view->with('admin_languages', $languages);
        });

        view()->composer('admin.includes.header', fn (View $view) => ! $this->adminRequest() ? null : $view->with([
            'notifications' => Notification::query()->orderByDesc('id')->limit(20)->get(),
            'totalUnreadNotifications' => $this->unreadNotifications(),
        ]));

        view()->composer('admin.includes.sidebar', function (View $view) {
            if (! $this->adminRequest()) {
                return;
            }
            $pending = BlogComment::query()->where('active', 0)->count();
            $view->with([
                'totalUnapprovedComments' => $pending > 50 ? '50+' : $pending,
                'totalUnreadNotifications' => $this->unreadNotifications(),
            ]);
        });

        view()->composer('admin.users.userdetails', fn (View $view) => ! $this->adminRequest() ? null : $view->with(
            'plans', app(PlanCatalog::class)->plans(true)
        ));
    }

    private function composeFrontendViews(): void
    {
        view()->composer('marketing.blog.sidebar', fn (View $view) => ! $this->frontendRequest() ? null : $view->with($this->quietly(
            fn () => $this->blogSidebar(),
            ['blogCategories' => collect(), 'recentBlogs' => collect(), 'blogTags' => [], 'testimonials' => collect()],
        )));

        // Footer links: active CMS pages in the current language or language-neutral. The footer view is named after the
        // ACTIVE THEME of the request (`<theme>layouts.includes.footer`), which a worker cannot know at boot.
        view()->composer('*layouts.includes.footer', function (View $view) {
            if (! $this->frontendRequest() || $view->name() !== active_theme().'layouts.includes.footer') {
                return;
            }
            $view->with('pages', Page::query()
                ->where('active', 1)
                ->where(fn ($q) => $q->where('translation_lang', get_lang())->orWhereNull('translation_lang'))
                ->get());
        });
    }

    /**
     * Blog sidebar: categories with post counts, the 3 newest published posts,
     * the distinct tags of all published posts, and up to 5 testimonials.
     *
     * @return array<string, mixed>
     */
    private function blogSidebar(): array
    {
        $published = fn () => Blog::query()->where('status', 'publish');

        $tags = $published()->pluck('tags')
            ->filter()
            ->flatMap(fn ($list) => explode(',', $list))
            ->unique()
            ->all();

        return [
            'blogCategories' => BlogCategory::query()->withCount('blogs')->get(),
            'recentBlogs' => $published()->orderByDesc('id')->limit(3)->get(),
            'blogTags' => $tags,
            'testimonials' => Testimonial::query()->limit(5)->get(),
        ];
    }

    private function unreadNotifications(): int
    {
        // P1: counted in SQL, never by hydrating rows.
        return Notification::query()->where('status', 0)->count();
    }

    /** Run `$read`; a missing table or other failure yields `$fallback` so a view never fatals on optional data. */
    private function quietly(callable $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
