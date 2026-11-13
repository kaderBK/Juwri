<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * A2 (upstream 855066828): turns the storefront's report-only
 * CSP into the enforcing header for stores on a template listed in
 * `csp.enforce_templates`, so themes can be enforced one at a time after a
 * browser pass. Runs outside AddCspHeaders (same nonce, same policy;
 * only the header name changes). With CSP_ENFORCE=true everything is already
 * enforcing and this is a no-op.
 */
class EnforceStorefrontCspPerTemplate
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $list = (array) config('csp.enforce_templates', []);
        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only', '');
        if ($list === [] || $policy === '' || config('csp.enforce')) {
            return $response;
        }

        $slug = $request->route('slug');
        $id = is_string($slug) ? Store::where('slug', $slug)->value('id') : null;
        $template = $id ? (string) (store_options($id)->store_template ?? '') : '';
        if ($template !== '' && in_array($template, $list, true)) {
            $response->headers->remove('Content-Security-Policy-Report-Only');
            $response->headers->set('Content-Security-Policy', $policy);
        }

        return $response;
    }
}
