<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * WI-R2: a route-scoping census (the IDOR-exposure claim was REFUTED — `tenant.owns`
 * is defence-in-depth behind per-controller findOrFail scoping, and four tenant
 * suites already exist). What was NOT covered: nothing fails CI when a NEW
 * model-bound authenticated dashboard route ships without controller scoping. This
 * enumerates that population and corroborates (fallibly) that each scopes.
 *
 * Reads the route table only — it NEVER issues a request and NEVER calls
 * gatherMiddleware() (which instantiates controllers whose constructors abort()).
 */
final class RouteTenantScopeCensus
{
    /**
     * Markers that CORROBORATE owner/staff/reseller scoping in a method body.
     * Deliberately NOT bare store_id / user_id / findOrFail —
     * those appear in hundreds of methods that write data without scoping, which
     * would make the corroboration vacuous. This is a hint, not proof.
     */
    public const MARKERS = [
        'dashboard_store_ids', 'dashboard_owner_id', 'belongsToCurrentOwner',
        '$this->authorize(', 'Gate::', 'abort_unless', 'abort_if',
        'auth()->id()', 'Auth::id()', '$request->user()', '->user()->id',
        "Auth::guard('staff')", '$this->tenant(',
    ];

    /**
     * Every model-bound, authenticated (auth / auth:staff / staff.dashboard),
     * NON-superadmin route, keyed by route name (falls back to "METHOD uri").
     *
     * @return array<string,array{action:string,params:list<string>,middleware:list<string>}>
     */
    public static function population(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $action = $r->getActionName();
            if (! str_contains($action, '@')) {
                continue; // closures + non-@ actions
            }
            [$class, $method] = explode('@', $action);
            if (! class_exists($class)) {
                continue;
            }

            $mw = array_values(array_filter($r->middleware(), 'is_string')); // NOT gatherMiddleware()
            if (in_array('admin', $mw, true)) {
                continue; // superadmin bypasses by design
            }
            if (! array_intersect($mw, ['auth', 'auth:staff', 'staff.dashboard'])) {
                continue;
            }

            try {
                $rm = new ReflectionMethod($class, $method);
            } catch (\Throwable $e) {
                continue;
            }

            $bound = [];
            foreach ($rm->getParameters() as $p) {
                $t = $p->getType();
                if (! $t instanceof ReflectionNamedType || $t->isBuiltin()) {
                    continue;
                }
                $n = $t->getName();
                if (! class_exists($n) || ! is_subclass_of($n, Model::class)) {
                    continue;
                }
                if (! in_array($p->getName(), $r->parameterNames(), true)) {
                    continue;
                }
                $bound[] = $p->getName();
            }
            if (! $bound) {
                continue;
            }

            $key = $r->getName() ?: (implode('|', $r->methods()).' '.$r->uri());
            $out[$key] = ['action' => $class.'@'.$method, 'params' => $bound, 'middleware' => $mw];
        }

        return $out;
    }

    /**
     * Corroboration, deliberately fallible: return the marker found in the method
     * body (or in a one-hop $this->helper() it calls), or null.
     */
    public static function scopeEvidence(string $class, string $method): ?string
    {
        $body = self::methodBody($class, $method);
        if ($body === null) {
            return null;
        }

        $found = self::firstMarker($body);
        if ($found !== null) {
            return $found;
        }

        // One hop into $this->someHelper( on the same class.
        if (preg_match_all('/\$this->(\w+)\s*\(/', $body, $m)) {
            foreach (array_unique($m[1]) as $helper) {
                if ($helper === $method) {
                    continue;
                }
                $hb = self::methodBody($class, $helper);
                if ($hb !== null && ($mark = self::firstMarker($hb)) !== null) {
                    return $mark;
                }
            }
        }

        return null;
    }

    private static function firstMarker(string $body): ?string
    {
        foreach (self::MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return $marker;
            }
        }

        return null;
    }

    private static function methodBody(string $class, string $method): ?string
    {
        try {
            $rm = new ReflectionMethod($class, $method);
        } catch (\Throwable $e) {
            return null;
        }
        $file = $rm->getFileName();
        $start = $rm->getStartLine();
        $end = $rm->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return null;
        }
        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
