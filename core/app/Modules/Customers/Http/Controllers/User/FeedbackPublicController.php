<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Customers\Models\CustomerFeedback;
use Juwri\Modules\Customers\Services\FeedbackService;
use Illuminate\Http\Request;

/**
 * SYSTEMS-RUN S-P1: public tokened survey — the diner rates their order. Star +
 * comment; on submit, ≥4 routes to the public review link, ≤3 stays private.
 */
class FeedbackPublicController extends Controller
{
    public function show(string $token)
    {
        $feedback = CustomerFeedback::where('token', $token)->firstOrFail();

        return view('feedback.survey', ['feedback' => $feedback]);
    }

    public function submit(Request $request, string $token)
    {
        $feedback = CustomerFeedback::where('token', $token)->firstOrFail();
        abort_if($feedback->status !== 'new', 410, ___('This survey was already completed.'));

        $data = $request->validate([
            'rating'  => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $decision = new FeedbackService()->submit($feedback, (int) $data['rating'], $data['comment'] ?? null);

        if ($decision['funnel'] === 'public_review' && !empty($decision['review_url'])) {
            return redirect()->away($decision['review_url']);
        }

        return view('feedback.thanks', ['feedback' => $feedback]);
    }
}
