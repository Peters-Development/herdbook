<?php

use App\Http\Controllers\StatusScreenController;
use App\Http\Middleware\AuthenticateStatusScreen;
use Illuminate\Support\Facades\Route;

/*
| The status screen for a wall display. Outside the `web` group on
| purpose: no session (with SESSION_DRIVER=database every poll without a
| cookie would write a session row) and no Livewire. The token sits in the
| link's fragment (/screen#<token>), which never reaches a server log; the
| page sends it as a Bearer token.
*/

Route::get('/screen', [StatusScreenController::class, 'show'])->name('screen');

Route::get('/api/screen', [StatusScreenController::class, 'status'])
    ->middleware(AuthenticateStatusScreen::class)
    ->name('screen.status');
