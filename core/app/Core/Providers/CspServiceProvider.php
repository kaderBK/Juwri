<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Providers;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Pci\PaymentPages;
use Juwri\Core\Support\Csp\RandomNonce;

/**
 * P1-00a: CSP nonce binding + `@nonce` Blade directive (replaces
 * spatie/laravel-csp's provider, J18). `csp_nonce()` is in Helpers/CspHelper.php.
 * One nonce per application instance, as before.
 */
class CspServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('csp-nonce', function ($app): string {
            if (! config('csp.nonce_enabled', true)) {
                return '';
            }

            return (string) $app->make((string) config('csp.nonce_generator', RandomNonce::class))->generate();
        });
    }

    public function boot(): void
    {
        Blade::directive('nonce', fn () => '<?php echo \'nonce="\'.csp_nonce().\'"\'; ?>');
        // Livewire (Pulse dashboard) and Vite stamp Vite::cspNonce() on the <style>/<script> tags they inject; without
        // it Livewire's auto-injected `<style >` carried no nonce and was blocked by the enforced dashboard CSP.
        $stamp = static function (Vite $vite): void {
            if ($vite->cspNonce() === null) {
                $vite->useCspNonce(csp_nonce());
            }
        };
        if ($this->app->resolved(Vite::class)) {
            $stamp($this->app->make(Vite::class));
        }
        $this->app->afterResolving(Vite::class, $stamp);
        // PCI DSS 4.0 6.4.3: a rendered payment-page view marks the request for its strict CSP (AddPaymentPageCsp).
        PaymentPages::register();
    }
}
