<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CounterController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Invités uniquement : inscription / connexion.
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// Connectés uniquement. Ces routes /api/* vivent dans web.php (et non api.php)
// pour bénéficier de la session : $request->user() est ainsi disponible.
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [CounterController::class, 'index']);

    Route::get('/api/counter/add', [CounterController::class, 'add']);
    Route::get('/api/counter/stats', [CounterController::class, 'stats']);
    Route::get('/api/leaderboard', [CounterController::class, 'leaderboard']);
});

// JSON readiness endpoint, consumed by the Kubernetes readiness probe and by the
// Argo Rollouts canary analysis (web provider needs a JSON body). Reflects real
// application state: it opens a DB connection and honours the APP_FORCE_UNHEALTHY
// demo flag. Returns 200 {"healthy":true} when ready, 503 {"healthy":false}
// otherwise.
Route::get('/readyz', function () {
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
