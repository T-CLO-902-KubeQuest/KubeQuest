<?php

use App\Http\Controllers\CounterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by bootstrap/app.php and are automatically
| prefixed with "/api".
|
*/

// Total global, public et stateless (pas besoin de session).
// add/stats/leaderboard sont dans web.php pour accéder à l'utilisateur connecté.
Route::get('counter/count', [CounterController::class, 'get']);
