<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\ContactController;
use App\Http\Controllers\Account\OtherSessionsController;
use App\Http\Controllers\Account\RepresentedPersonController;
use App\Http\Controllers\Account\SecurityController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::delete('/user/other-sessions', [OtherSessionsController::class, 'destroy'])->name('other-sessions.destroy');
});

Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::put('/', [AccountController::class, 'update'])->name('update');

    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::post('/contacts/{contact}/verification', [ContactController::class, 'sendVerification'])->name('contacts.verification.send');
    Route::post('/contacts/{contact}/verify', [ContactController::class, 'verify'])->name('contacts.verify');

    Route::get('/security', [SecurityController::class, 'show'])->name('security');

    Route::get('/represented', [RepresentedPersonController::class, 'index'])->name('represented.index');
    Route::get('/represented/{person}', [RepresentedPersonController::class, 'show'])->name('represented.show');
    Route::put('/represented/{person}', [RepresentedPersonController::class, 'update'])->name('represented.update');
});
