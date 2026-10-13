<?php

use App\Models\Team;
use App\Models\User;

test('users can register as individuals', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Individual Driver',
        'email' => 'driver@example.com',
        'password' => 'password123#',
        'password_confirmation' => 'password123#',
        'account_type' => 'individual',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $user = User::where('email', 'driver@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->account_type)->toBe('individual');
    expect($user->onboarding_completed)->toBeFalse();
});

test('users can register as organization with pending status', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Org Administrator',
        'email' => 'admin@fleetcorp.mw',
        'password' => 'password123#',
        'password_confirmation' => 'password123#',
        'account_type' => 'organization',
        'organization_name' => 'Fleet Corp Malawi',
        'phone_number' => '+265 999 111 222',
        'business_address' => 'Ginnery Corner, Blantyre',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $user = User::where('email', 'admin@fleetcorp.mw')->first();
    expect($user->account_type)->toBe('organization');

    $team = Team::where('name', 'Fleet Corp Malawi')->first();
    expect($team)->not->toBeNull();
    expect($team->status)->toBe('pending');
    expect($team->created_by)->toBe($user->id);
});
