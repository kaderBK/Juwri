<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Juwri\Core\Contracts\Content\ResellerContent;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Models\User;
use RuntimeException;

/** P1-13 Community default: there are no resellers (white-label partners are an ee feature). */
final class NoResellers implements ResellerContent, ResellerContext, ResellerSite
{
    public function forRequest(): ?Model
    {
        return null;
    }

    public function forUser(User $user): ?Model
    {
        return null;
    }

    public function find(int $resellerId): ?Model
    {
        return null;
    }

    public function ids(): array
    {
        return [];
    }

    public function ownsReseller(int $userId, bool $activeOnly = false): bool
    {
        return false;
    }

    public function activeCustomDomains(): array
    {
        return [];
    }

    public function isActiveCustomDomain(string $host): bool
    {
        return false;
    }

    public function holdsDomain(string $host): bool
    {
        return false;
    }

    public function entitled(Model $reseller, string $key): bool
    {
        return false;
    }

    public function announcementsFor(User $user): Collection
    {
        return collect();
    }

    public function ownerUserIds(): array
    {
        return [];
    }

    public function audit(int $resellerId, string $action, array $meta = [], ?int $actorUserId = null): void {}

    public function cancelCustomerSubscriptions(User $user): void {}

    public function notifyCustomerDeleted(User $user, array $payload): void {}

    public function home(Model $reseller): mixed
    {
        return null;
    }

    public function pricing(Model $reseller): mixed
    {
        abort(404);
    }

    public function page(Request $request, string $slug): mixed
    {
        abort(404);
    }

    public function blogIndex(Request $request, ?string $tag = null): mixed
    {
        abort(404);
    }

    public function blogShow(Request $request, string $slug): mixed
    {
        abort(404);
    }

    public function contact(Model $reseller): mixed
    {
        abort(404);
    }

    public function contactSend(Request $request, Model $reseller): mixed
    {
        abort(404);
    }

    public function sitemapEntries(Model $reseller): array
    {
        return [];
    }

    public function brandName(int $resellerId): string
    {
        throw new RuntimeException("Reseller [$resellerId] does not exist (Community edition).");
    }

    public function defaultLocale(int $resellerId): string
    {
        return (string) config('app.locale', 'en');
    }

    public function ownerUserId(int $resellerId): ?int
    {
        return null;
    }

    public function publishedPosts(int $resellerId, int $limit): array
    {
        return [];
    }

    public function postSlugTaken(int $resellerId, string $slug): bool
    {
        return false;
    }

    public function createPost(int $resellerId, array $attributes): int
    {
        throw new RuntimeException('Reseller blogs are not part of the Community edition.');
    }

    public function postsCreatedSince(int $resellerId, array $storeIds, \DateTimeInterface $since): int
    {
        return 0;
    }

    public function pageCapDefaults(): array
    {
        return ['pages' => 10, 'blogPosts' => 100];
    }

    public function lockedTemplateUsage(): array
    {
        return [];
    }
}
