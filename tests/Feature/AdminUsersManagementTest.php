<?php

use App\Models\Team;
use App\Models\User;
use App\Notifications\PlatformUserInvitation;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('microi standalone routes are accessible by authenticated tenant users', function () {
    $user = User::factory()->create([
        'system_role' => 'micromek_staff',
        'account_type' => 'organization',
    ]);

    $team = Team::factory()->create([
        'created_by' => $user->id,
        'status' => 'approved',
    ]);
    $team->members()->attach($user, ['role' => 'owner']);
    $user->update(['current_team_id' => $team->id]);

    $this->actingAs($user);

    $this->get(route('tracking', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Live Vehicle Tracking');

    $this->get(route('history', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Historical Journey Playback');

    $this->get(route('vehicles', ['current_team' => $team->slug]))
        ->assertOk();

    $this->get(route('admin.users', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('User & Account Directory');
});

test('admin users livewire component renders and can toggle user status', function () {
    $admin = User::factory()->create([
        'system_role' => 'super_admin',
    ]);
    $targetUser = User::factory()->create([
        'system_role' => 'customer',
        'is_active' => true,
    ]);

    $team = Team::factory()->create(['created_by' => $admin->id]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $admin->update(['current_team_id' => $team->id]);

    $this->actingAs($admin);

    Livewire::test('admin.users-livewire')
        ->assertSee($targetUser->email)
        ->call('toggleUserStatus', $targetUser->id);

    expect($targetUser->fresh()->is_active)->toBeFalse();
});

test('micromek admin can create and invite a new platform user', function () {
    Notification::fake();

    $admin = User::factory()->create([
        'system_role' => 'super_admin',
    ]);

    $team = Team::factory()->create(['created_by' => $admin->id]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $admin->update(['current_team_id' => $team->id]);

    $this->actingAs($admin);

    Livewire::test('admin.users-livewire')
        ->call('openCreateUser')
        ->set('newUserName', 'Kondwani Banda')
        ->set('newUserEmail', 'kondwani.banda@example.com')
        ->set('newUserPhone', '+265999888777')
        ->set('newUserRole', 'customer')
        ->set('newUserAccountType', 'individual')
        ->call('createUser')
        ->assertHasNoErrors();

    $newUser = User::where('email', 'kondwani.banda@example.com')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->name)->toBe('Kondwani Banda')
        ->and($newUser->system_role)->toBe('customer')
        ->and($newUser->currentTeam)->not->toBeNull()
        ->and($newUser->currentTeam->is_personal)->toBeTrue();

    Notification::assertSentTo($newUser, PlatformUserInvitation::class);
});

test('micromek admin can create a user and assign them to a commercial organization', function () {
    Notification::fake();

    $admin = User::factory()->create([
        'system_role' => 'micromek_staff',
    ]);

    $team = Team::factory()->create(['created_by' => $admin->id]);
    $team->members()->attach($admin, ['role' => 'owner']);
    $admin->update(['current_team_id' => $team->id]);

    $commercialOrg = Team::factory()->create([
        'name' => 'Apex Logistics Malawi',
        'is_personal' => false,
        'status' => 'approved',
    ]);

    $this->actingAs($admin);

    Livewire::test('admin.users-livewire')
        ->call('openCreateUser')
        ->set('newUserName', 'Driver Chimwemwe')
        ->set('newUserEmail', 'driver.chimwemwe@apex.mw')
        ->set('newUserRole', 'customer')
        ->set('newUserAccountType', 'organization')
        ->set('newUserTeamId', $commercialOrg->id)
        ->set('newUserTeamRole', 'member')
        ->call('createUser')
        ->assertHasNoErrors();

    $newUser = User::where('email', 'driver.chimwemwe@apex.mw')->first();
    expect($newUser)->not->toBeNull()
        ->and($commercialOrg->members()->where('users.id', $newUser->id)->exists())->toBeTrue()
        ->and($newUser->current_team_id)->toBe($commercialOrg->id);
});
