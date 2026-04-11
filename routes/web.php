<?php

use CodeSphere\OAuth\Http\Controllers\CodeSphereAuthController;
use Illuminate\Support\Facades\Route;

$prefix = (string) config('codesphere.routes.prefix', 'auth');

Route::middleware('web')->prefix($prefix)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/redirect', [CodeSphereAuthController::class, 'redirect'])
            ->name('codesphere.redirect');

        Route::get('/callback', [CodeSphereAuthController::class, 'callback'])
            ->name('codesphere.callback');
    });

    Route::post('/logout', [CodeSphereAuthController::class, 'logout'])
        ->middleware('auth')
        ->name('codesphere.logout');
});

// Optional: register a default /login route that immediately starts the
// OAuth flow. Disable this in your config if your app provides its own.
if (config('codesphere.routes.register_login_route', true)) {
    Route::middleware(['web', 'guest'])->get('/login', function () {
        return redirect()->route('codesphere.redirect');
    })->name('login');
}
