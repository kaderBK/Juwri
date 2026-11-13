<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * A3 — builds the v1 API discovery index AND the OpenAPI 3.1 document from the live
 * router, so a new `api.v1.*` endpoint self-documents (no hand-maintained list to drift).
 */
class OpenApiGenerator
{
    /** Route names that are API meta, not endpoints. */
    private const META = ['api.v1.index', 'api.v1.openapi'];

    /**
     * The `api.v1.*` endpoint routes (excluding meta), each once per HTTP verb.
     *
     * @return array<int,array{method:string,uri:string,name:string,group:string}>
     */
    public static function endpoints(): array
    {
        $out = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $name = (string) $route->getName();
            if ($name === '' || ! str_starts_with($name, 'api.v1.') || in_array($name, self::META, true)) {
                continue;
            }

            $group = explode('.', substr($name, strlen('api.v1.')))[0] ?: 'root';
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[] = [
                    'method' => $method,
                    'uri' => '/'.ltrim($route->uri(), '/'),
                    'name' => $name,
                    'group' => $group,
                ];
            }
        }

        usort($out, fn ($a, $b) => [$a['group'], $a['uri'], $a['method']] <=> [$b['group'], $b['uri'], $b['method']]);

        return $out;
    }

    /**
     * The machine-readable discovery index shape: `METHOD /uri` grouped by name segment.
     *
     * @return array<string,array<int,string>>
     */
    public static function discoveryIndex(): array
    {
        $grouped = [];
        foreach (self::endpoints() as $e) {
            $grouped[$e['group']][] = str_pad($e['method'], 6).' '.$e['uri'];
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * A valid OpenAPI 3.1 document covering every `api.v1.*` route. Descriptions are
     * intentionally light (the human docs live on /developers); the value here is a
     * always-accurate, machine-consumable path+method+security inventory.
     *
     * @return array<string,mixed>
     */
    public static function spec(): array
    {
        $paths = [];
        foreach (self::endpoints() as $e) {
            $path = $e['uri'];
            $verb = strtolower($e['method']);

            $params = [];
            if (preg_match_all('/\{(\w+)\??\}/', $path, $m)) {
                foreach ($m[1] as $p) {
                    $params[] = [
                        'name' => $p,
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                    ];
                }
            }

            $op = [
                'operationId' => $e['name'].'.'.$verb,
                'summary' => ucfirst($verb).' '.$path,
                'tags' => [$e['group']],
                'responses' => [
                    '200' => ['description' => 'OK'],
                    '4XX' => ['description' => 'Client error (see the standard error envelope on /developers).'],
                ],
                'security' => [['ApiToken' => []], ['StorefrontToken' => []], ['Passport' => []]],
            ];
            if ($params) {
                $op['parameters'] = $params;
            }

            $paths['/'.ltrim($path, '/')][$verb] = $op;
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('app.name', 'Menu').' API',
                'version' => 'v1',
                'description' => 'Auto-generated from the live router. Human docs: '.url('/developers'),
            ],
            // The path keys are absolute (`/api/v1/...`, pinned 1:1 to the router by OpenApiParityTest), so the server is the
            // site root. Before (ZAP, P4-17) it was `/api/v1` too and every client called `/api/v1/api/v1/...` (404).
            'servers' => [['url' => url('/')]],
            'components' => [
                'securitySchemes' => [
                    'ApiToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Owner/reseller personal token.'],
                    'StorefrontToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Storefront-Token'],
                    'Passport' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => ['authorizationUrl' => url('/oauth/authorize'), 'tokenUrl' => url('/oauth/token'), 'scopes' => new \stdClass]]],
                ],
            ],
            'paths' => $paths,
        ];
    }
}
