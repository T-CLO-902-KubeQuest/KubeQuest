<?php

use App\Http\Controllers\CounterController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by bootstrap/app.php and are automatically
| prefixed with "/api". The "api" group is stateless (no session, no CSRF),
| which is exactly what machine-consumed endpoints want.
|
*/

// Total global, public et stateless (pas besoin de session).
// add/stats/leaderboard sont dans web.php pour accéder à l'utilisateur connecté.
Route::get('counter/count', [CounterController::class, 'get']);

// JSON readiness endpoint (URL: /api/readyz), consumed by the Kubernetes readiness
// probe and the Argo Rollouts canary analysis. It lives here, in the stateless API
// group, so the continuous probing does not create a `sessions` row on every hit
// (SESSION_DRIVER=database). Opens a DB connection and honours APP_FORCE_UNHEALTHY.
// Returns 200 {"healthy":true} when ready, 503 {"healthy":false} otherwise.
Route::get('readyz', function () {
    try {
        DB::connection()->getPdo();

        if (env('APP_FORCE_UNHEALTHY', false)) {
            throw new \RuntimeException('Forced unhealthy for canary demo');
        }
    } catch (\Throwable $e) {
        return response()->json(['healthy' => false, 'error' => $e->getMessage()], 503);
    }

    return response()->json(['healthy' => true], 200);
});
