<?php
// P6-136 throwaway HTTP repro (not committed): two web-group routes on the real kernel.
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class, function ($app) {
    if (getenv('RACE_MODE') === 'stock') {
        $app->singleton('session', fn ($a) => new Illuminate\Session\SessionManager($a));
    }
});
$app->booted(function () use ($app) {
    $r = $app['router'];
    $r->middleware('web')->group(function () use ($r) {
        $r->get('__race/slow', function () { usleep(1500000); session()->put('slow_seen', 1); return 'slow'; });
        $r->get('__race/set/{v}', function ($v) { session()->put('locale', $v); session()->flash('status', 'Saved '.$v); return 'set'; });
        $r->get('__race/read', fn () => response()->json(['locale' => session('locale'), 'status' => session('status')]));
    });
    $r->getRoutes()->refreshNameLookups();
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$req = Illuminate\Http\Request::capture();
$res = $kernel->handle($req);
$res->send();
$kernel->terminate($req, $res);
