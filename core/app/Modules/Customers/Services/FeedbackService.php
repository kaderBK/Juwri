<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services;

use App\Models\Order;
use Illuminate\Support\Str;
use Juwri\Core\Support\SafeUrl;
use Juwri\Modules\Customers\Models\CustomerFeedback;

/**
 * SYSTEMS-RUN S-P1: customer feedback lifecycle. One request per order
 * (idempotent), tokened survey submission, low-rating alert, and the ≥4 → public
 * review / ≤3 → private funnel decision.
 */
class FeedbackService
{
    /** Create a feedback request for an order (idempotent per order). */
    public function requestForOrder(Order $order): ?CustomerFeedback
    {
        if (! $order->customer_id) {
            return null;
        }
        $existing = CustomerFeedback::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        return CustomerFeedback::create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'token' => Str::random(48),
            'status' => 'new',
        ]);
    }

    /** Submit the diner's response. Returns the funnel decision. */
    public function submit(CustomerFeedback $feedback, int $rating, ?string $comment = null, array $answers = []): array
    {
        $rating = max(1, min(5, $rating));
        $feedback->update([
            'rating' => $rating,
            'comment' => $comment,
            'answers' => $answers,
            'status' => 'responded',
            'submitted_at' => now(),
        ]);

        // Low-rating alert seam (owner notification).
        if ($rating <= 2) {
            try {
                if (function_exists('log_activity')) {
                    log_activity('feedback.low_rating', ['order_id' => $feedback->order_id, 'rating' => $rating]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'funnel' => $rating >= 4 ? 'public_review' : 'private',
            'review_url' => $rating >= 4 ? (string) SafeUrl::web((string) store_options($feedback->store_id, 'google_review_url', '')) : null,
        ];
    }

    public function respond(CustomerFeedback $feedback, string $reply): void
    {
        $feedback->update(['owner_reply' => $reply]);
    }

    public function close(CustomerFeedback $feedback): void
    {
        $feedback->update(['status' => 'closed']);
    }
}
