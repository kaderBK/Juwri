<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Reflector;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P1-10: implicit model binding by an integer primary key, hardened for PostgreSQL.
 *
 * `/orders/not-a-number` used to reach `where id = 'not-a-number'`: MySQL and sqlite quietly match nothing
 * (and MySQL even matches `12abc` as 12), PostgreSQL raises 22P02 and answers 500. A segment that cannot be
 * an integer key is now a 404 on every driver, before any query runs. Only default bindings are checked:
 * explicit `Route::bind`, `{model:field}` bindings and models with their own resolveRouteBinding() are
 * left exactly as they are.
 */
class SubstituteBindingsSafe extends SubstituteBindings
{
    public function handle($request, Closure $next)
    {
        $this->rejectNonNumericKeys($request);

        return parent::handle($request, $next);
    }

    private function rejectNonNumericKeys(Request $request): void
    {
        $route = $request->route();
        if ($route === null) {
            return;
        }

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $name = $parameter->getName();
            $value = $route->parameter($name);
            if (! is_string($value) || $value === '' || $route->bindingFieldFor($name) !== null) {
                continue;
            }
            if ($this->router->getBindingCallback($name) !== null) {
                continue;
            }
            $class = Reflector::getParameterClassName($parameter);
            if ($class === null || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            if ((new ReflectionMethod($class, 'resolveRouteBinding'))->getDeclaringClass()->getName() !== Model::class) {
                continue;
            }
            /** @var Model $model */
            $model = new $class;
            if ($model->getKeyType() !== 'int' || $model->getRouteKeyName() !== $model->getKeyName()) {
                continue;
            }
            if (! ctype_digit($value) || strlen($value) >= 19) {
                throw new NotFoundHttpException;
            }
        }
    }
}
