<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Support\BioLinkOutbound;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Integrations\Jobs\CreateGbpPostJob;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientFactory;

/**
 * GOOGLE-PARTNER Lane B / B4: Local Posts composer + recent-posts list. Composes a
 * post (summary + optional CTA button + optional store photo) and queues it to
 * Google via CreateGbpPostJob; recent posts are read live from the engagement
 * client. Tenant-scoped (cross-tenant → 403); behind the gbp_link route gate.
 */
class GbpPostsController extends Controller
{
    use ResolvesCurrentStore;

    /** CTA button action types Google accepts (+ NONE = no button). CALL takes no url. */
    private const CTA_TYPES = ['NONE', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    /**
     * Typed override of the shared trait's untyped return (keeps PHPStan level 6
     * clean for this new controller without touching the shared trait).
     *
     * @return array<int, int>
     */
    protected function storeIds(): array
    {
        return dashboard_store_ids();
    }

    public function index(Request $request): View
    {
        $store = $this->currentRestaurant($request);
        $creds = Credential::for('gbp', $store->id) ?? [];
        $connected = ! empty($creds['location_id']);

        $recent = [];
        if ($connected) {
            $recent = GbpEngagementClientFactory::make($store->id)
                ->listLocalPosts((string) $creds['location_id'])['localPosts'] ?? [];
        }

        return view('owner.gbp.posts', [
            'rid' => $store->id,
            'connected' => $connected,
            'ctaTypes' => self::CTA_TYPES,
            'photos' => $this->availablePhotos($store),
            'recent' => $recent,
            // biolink-tpl lane-d (T2-4a): offer the bio URL as the post's CTA link;
            // null (helper hidden) unless the bio page is live.
            'bioUrl' => BioLinkOutbound::liveUrl($store, 'social'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $creds = Credential::for('gbp', $store->id) ?? [];
        if (empty($creds['location_id'])) {
            return back()->withErrors(['gbp' => ___('Connect your Google Business location first.')]);
        }

        $data = $request->validate([
            // GBP Local Post summary caps at 1500 chars.
            'summary' => ['required', 'string', 'max:1500'],
            'cta_type' => ['nullable', Rule::in(self::CTA_TYPES)],
            'cta_url' => ['nullable', 'url', 'max:2048'],
            'photo' => ['nullable', 'in:cover,logo'],
        ]);

        $ctaType = $data['cta_type'] ?? 'NONE';
        // Every CTA except CALL needs a destination URL.
        if (! in_array($ctaType, ['NONE', 'CALL'], true) && empty($data['cta_url'])) {
            throw ValidationException::withMessages(['cta_url' => ___('Add a link for this button.')]);
        }

        $body = [
            'languageCode' => app()->getLocale() ?: 'en',
            'summary' => $data['summary'],
            'topicType' => 'STANDARD',
        ];
        if ($ctaType !== 'NONE') {
            $cta = ['actionType' => $ctaType];
            if ($ctaType !== 'CALL') {
                $cta['url'] = $data['cta_url'];
            }
            $body['callToAction'] = $cta;
        }
        if (! empty($data['photo'])) {
            $photos = $this->availablePhotos($store);
            if (! empty($photos[$data['photo']])) {
                $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $photos[$data['photo']]]];
            }
        }

        CreateGbpPostJob::dispatch($store->id, $body);

        return back()->with('success', ___('Post queued for Google.'));
    }

    /**
     * The store's own images usable as a post photo (public URLs).
     *
     * @return array<string, string>
     */
    private function availablePhotos(Store $store): array
    {
        $out = [];
        if (! empty($store->cover_image)) {
            $out['cover'] = media_url('storage/store/cover/', $store->cover_image, $store->id);
        }
        if (! empty($store->main_image)) {
            $out['logo'] = media_url('storage/store/logo/', $store->main_image, $store->id);
        }

        return $out;
    }
}
