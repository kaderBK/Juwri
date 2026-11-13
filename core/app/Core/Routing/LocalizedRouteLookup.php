<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;

/**
 * Wraps the route table for URL generation in worker mode. Localized routes are registered once per language with the
 * names `ar.pricing`, `fr.pricing`... next to the plain `pricing`; php-fpm registers only the group the current request
 * is in, so there `route('pricing')` already answers `/ar/pricing` while the visitor is on an `/ar/...` page. This
 * lookup gives the same answer: when the CURRENT request's first segment is a registered language prefix, `getByName('x')`
 * returns `<prefix>.x` if it exists. Everything else is delegated untouched.
 */
final class LocalizedRouteLookup implements RouteCollectionInterface
{
    /** @var array<string, Route> */
    private array $cache = [];

    public function __construct(private RouteCollectionInterface $routes, private \Closure $prefix) {}

    public function inner(): RouteCollectionInterface
    {
        return $this->routes;
    }

    private function localized(string $name): ?Route
    {
        $prefix = ($this->prefix)();
        if ($prefix === null) {
            return null;
        }
        $key = $prefix.'|'.$name;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $route = $this->routes->getByName(\Juwri\Core\Support\LocalizedRoutes::NAME_PREFIX.$name);
        if ($route === null) {
            return null;
        }
        // The single `{locale}/...` route, with the request's language filled in (a copy per language, built once).
        $copy = clone $route;
        $copy->setUri(str_replace('{locale}', $prefix, $route->uri()));
        // the clone carries the original's compiled pattern and parameter names (which still include `{locale}`): drop
        // them so URL generation compiles the filled-in URI (positional `route('x', $slug)` args must not fill `locale`)
        \Closure::bind(function (): void {
            $this->compiled = null; // @phpstan-ignore assign.propertyType
            $this->parameterNames = null;
            $this->parameters = null;
        }, $copy, Route::class)();

        return $this->cache[$key] = $copy;
    }

    public function getByName($name)
    {
        return $this->localized($name) ?? $this->routes->getByName($name);
    }

    public function hasNamedRoute($name)
    {
        return $this->routes->hasNamedRoute($name);
    }

    public function add(Route $route)
    {
        return $this->routes->add($route);
    }

    public function refreshNameLookups()
    {
        $this->routes->refreshNameLookups();
    }

    public function refreshActionLookups()
    {
        $this->routes->refreshActionLookups();
    }

    public function match(Request $request)
    {
        return $this->routes->match($request);
    }

    public function get($method = null)
    {
        return $this->routes->get($method);
    }

    public function getByAction($action)
    {
        return $this->routes->getByAction($action);
    }

    public function getRoutes()
    {
        return $this->routes->getRoutes();
    }

    /** @return array<string, array<string, Route>> */
    public function getRoutesByMethod()
    {
        return $this->routes->getRoutesByMethod();
    }

    public function getRoutesByName()
    {
        return $this->routes->getRoutesByName();
    }
}
