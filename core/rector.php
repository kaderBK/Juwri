<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\Rector\PropertyFetch\RenamePropertyRector;
use Rector\Set\ValueObject\SetList;
use Rector\Transform\Rector\String_\StringToClassConstantRector;
use RectorLaravel\Rector\Class_\AddMockConsoleOutputFalseToConsoleTestsRector;
use RectorLaravel\Rector\Class_\ModelCastsPropertyToCastsMethodRector;
use RectorLaravel\Rector\ClassMethod\MigrateToSimplifiedAttributeRector;
use RectorLaravel\Rector\ClassMethod\ScopeNamedClassMethodToScopeAttributedClassMethodRector;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
use RectorLaravel\Set\LaravelLevelSetList;

/*
 * juwri upgrade rules (P1-00a, J25-12). Kept for future framework/PHP upgrades:
 * bump the sets below, run `vendor/bin/rector process --dry-run`, review, then
 * run without --dry-run and the full suite (MySQL + PostgreSQL).
 *
 * - Laravel: every upgrade set up to 13. The Laravel 13 *attribute* rules
 *   ($fillable/$table/$signature/... -> #[Fillable]/#[Table]/...) are left out on
 *   purpose for now: they rewrite nearly every model/command/job while P1-03/P1-08
 *   rebuild and move those classes anyway. Switch to UP_TO_LARAVEL_130 when the
 *   module moves are done.
 * - PHP: the 8.4-specific rules (explicit nullable types, RoundingMode, ...).
 * - PHPUnit 12: doc-comment annotations -> attributes (12 ignores annotations).
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/plugins',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/tests/Fixtures',
        __DIR__.'/tests/e2e',
        '*/node_modules/*',
        '*.blade.php',
        // Not behaviour-neutral on this codebase (dry run, P1-00a): renames a real
        // `events` column to $dispatchesEvents, turns view('auth.login') into
        // view(Login::class), and $request->get() (reads route attributes too) into
        // input().
        RenamePropertyRector::class,
        StringToClassConstantRector::class,
        RenameMethodRector::class,
        RenameClassRector::class,
        // Style modernisations that rename methods/properties across ~350 models
        // (scopeX -> #[Scope] x, getXAttribute -> x(): Attribute, $casts -> casts(),
        // bind(Class, fn) -> bind(fn(): Class)). P1-03/P1-08 rebuild those classes;
        // decide there.
        ModelCastsPropertyToCastsMethodRector::class,
        ScopeNamedClassMethodToScopeAttributedClassMethodRector::class,
        MigrateToSimplifiedAttributeRector::class,
        ContainerBindConcreteWithClosureOnlyRector::class,
        // Breaks artisan()->assertFailed()/expectsOutput() chains in 8 tests.
        AddMockConsoleOutputFalseToConsoleTestsRector::class,
    ])
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_130_WITHOUT_ATTRIBUTES,
        SetList::PHP_84,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ])
    ->withParallel(300, 6, 20);
