<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P6-95: the storefront CSP is enforced (config/csp.php), which blocks inline on*="" handlers, so every pizzaflow control that
 * used onclick="" was dead in the browser (add to cart, open cart, place order). The markup and the script now use data-pf-act
 * with one delegated listener; this keeps inline handlers from coming back.
 */
it('has no inline event handlers in the pizzaflow template or script', function (string $path) {
    expect(file_get_contents($path))->not->toMatch('/\son(click|change|input|submit|keydown|keyup)\s*=/i');
})->with([
    'template' => [__DIR__.'/../../../resources/views/store_templates/pizzaflow/index.blade.php'],
    'dish card' => [__DIR__.'/../../../resources/views/store_templates/pizzaflow/includes/dish-card.blade.php'],
    'script' => [__DIR__.'/../../../../assets/store_templates/pizzaflow/js/script.js'],
]);

it('wires every data-pf-act in the template to a PizzaFlow method', function () {
    $template = file_get_contents(__DIR__.'/../../../resources/views/store_templates/pizzaflow/index.blade.php');
    $script = file_get_contents(__DIR__.'/../../../../assets/store_templates/pizzaflow/js/script.js');

    preg_match_all('/data-pf-act="([A-Za-z_]+)"/', $template.$script, $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach (array_unique($matches[1]) as $action) {
        expect($script)->toContain('window.PizzaFlow.'.$action.' =');
    }
});
