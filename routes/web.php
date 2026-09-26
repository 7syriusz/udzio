<?php

use App\Http\Controllers\Account\OtherSessionsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::delete('/user/other-sessions', [OtherSessionsController::class, 'destroy'])->name('other-sessions.destroy');
});
