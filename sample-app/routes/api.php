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

Route::get('counter/add', [CounterController::class, 'add']);
Route::get('counter/count', [CounterController::class, 'get']);
