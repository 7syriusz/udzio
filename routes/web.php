<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\ContactController;
use App\Http\Controllers\Account\OtherSessionsController;
use App\Http\Controllers\Account\RepresentedPersonController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\StructureController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:20,1')->name('locale.update');

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

// Organizations and their structure (E3.10b, pattern E3.10f). Reads through DataVisibility, changes through the
// authorized domain actions; every action has its own screen with "Anuluj".
Route::middleware(['auth', 'verified'])->prefix('organizations')->name('organizations.')->group(function (): void {
    Route::get('/', [OrganizationController::class, 'index'])->name('index');
    Route::get('/new', [OrganizationController::class, 'create'])->name('create');
    Route::post('/', [OrganizationController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/archived/{archived}', [StructureController::class, 'history'])->name('history');
    Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
    Route::get('/{organization}/units/new', [StructureController::class, 'createUnit'])->name('units.create');
    Route::post('/{organization}/units', [StructureController::class, 'storeUnit'])->name('units.store');
    Route::get('/{organization}/name', [StructureController::class, 'editName'])->name('rename.edit');
    Route::put('/{organization}/name', [StructureController::class, 'rename'])->name('rename');
    Route::get('/{organization}/move', [StructureController::class, 'chooseTarget'])->name('move.choose');
    Route::get('/{organization}/move/confirm', [StructureController::class, 'confirmMove'])->name('move.confirm');
    Route::put('/{organization}/parent', [StructureController::class, 'move'])->name('move');
    Route::get('/{organization}/archive', [StructureController::class, 'confirmArchive'])->name('archive.confirm');
    Route::post('/{organization}/archive', [StructureController::class, 'archive'])->name('archive');
    Route::get('/{organization}/archived', [StructureController::class, 'archived'])->name('archived');
});
