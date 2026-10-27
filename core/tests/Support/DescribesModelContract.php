<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;

/**
 * P1-13 13i part 2b: the model-shape describer of the base-models contract (declared table / key / fillable / casts and
 * every relation a model declares), shared by the Community snapshot test and the Enterprise package's own snapshot test
 * (the snapshot of each lives next to its test).
 */
trait DescribesModelContract
{
    private function describe(string $class): array
    {
        /** @var Model $model */
        $model = new $class;
        $out = [
            'table' => $model->getTable(),
            'key' => $model->getKeyName(),
            'incrementing' => $model->getIncrementing(),
            'timestamps' => $model->usesTimestamps(),
            'fillable' => $model->getFillable(),
            'guarded' => $model->getGuarded(),
            'casts' => $model->getCasts(),
            'hidden' => $model->getHidden(),
            'sluggable' => method_exists($model, 'sluggable') ? $model->sluggable() : null,
            'relations' => [],
        ];
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $class || $m->isStatic() || $m->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if (str_starts_with($m->getName(), 'get') || str_starts_with($m->getName(), 'scope') || str_starts_with($m->getName(), 'is')) {
                continue;
            }
            try {
                $rel = $m->invoke($model);
            } catch (\Throwable) {
                continue;
            }
            if (! $rel instanceof Relation) {
                continue;
            }
            $out['relations'][$m->getName()] = [
                'type' => class_basename($rel),
                'related' => get_class($rel->getRelated()),
                // snapshot is in sqlite quoting; MySQL uses backticks
                'sql' => str_replace('`', '"', $rel->toBase()->toSql()),
                'eager' => array_keys($rel->getEagerLoads()),
            ];
        }
        // P1-13: relations an ee module adds with resolveRelationUsing() (were methods on these models).
        $resolvers = (new \ReflectionProperty(Model::class, 'relationResolvers'))->getValue();
        foreach (array_keys($resolvers[$class] ?? []) as $name) {
            $rel = $model->{$name}();
            $out['relations'][$name] = [
                'type' => class_basename($rel),
                'related' => get_class($rel->getRelated()),
                'sql' => str_replace('`', '"', $rel->toBase()->toSql()),
                'eager' => array_keys($rel->getEagerLoads()),
            ];
        }
        ksort($out['relations']);

        return $out;
    }
}
