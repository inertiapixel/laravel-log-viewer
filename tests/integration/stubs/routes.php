<?php

/*
 * Demo routes for the integration test. Local environment only.
 */

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

if (app()->environment('local')) {
    Route::middleware('web')->group(function () {
        Route::get('/ipx-login', function () {
            $user = User::query()->firstOrCreate(
                ['email' => 'ipx-demo@example.com'],
                ['name' => 'Log Viewer Demo', 'password' => bcrypt(str()->random(32))],
            );

            Auth::login($user);

            return redirect('/log-viewer');
        });

        Route::get('/ipx-seed', function () {
            Log::info('Integration info entry', ['order' => 42]);
            Log::warning('Integration warning entry');
            Log::error('Integration failure', ['exception' => new RuntimeException('Integration exception'), 'password' => 'hunter2']);

            return 'seeded';
        });
    });
}
