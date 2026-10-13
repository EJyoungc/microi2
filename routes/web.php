<?php

use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Team;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/dashboard', function () {
    $user = auth()->user();
    if (! $user) {
        return redirect()->route('login');
    }

    $team = $user->currentTeam ?? $user->personalTeam() ?? $user->teams()->first();
    if (! $team) {
        $team = Team::create([
            'name' => $user->name."'s Fleet",
            'is_personal' => true,
            'status' => 'approved',
            'created_by' => $user->id,
        ]);
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    }

    if ($user->current_team_id !== $team->id) {
        $user->update(['current_team_id' => $team->id]);
    }

    return redirect()->route('dashboard', ['current_team' => $team->slug]);
})->middleware(['auth']);

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::view('tracking', 'pages.tracking')->name('tracking');
        Route::view('history', 'pages.history')->name('history');
        Route::view('vehicles', 'pages.vehicles')->name('vehicles');

        // MicroMek Staff / Admin Management Routes
        Route::prefix('admin')->group(function () {
            Route::view('devices', 'pages.admin.devices')->name('admin.devices');
            Route::view('requests', 'pages.admin.requests')->name('admin.requests');
            Route::view('organizations', 'pages.admin.organizations')->name('admin.organizations');
            Route::view('users', 'pages.admin.users')->name('admin.users');
        });
    });

require __DIR__.'/settings.php';
