<?php

use App\Models\Counter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    $value = Counter::sum('count');
    return view('welcome', ['value' => $value]);
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
