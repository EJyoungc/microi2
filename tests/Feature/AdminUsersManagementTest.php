<?php

use App\Models\Team;
use App\Models\User;
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
