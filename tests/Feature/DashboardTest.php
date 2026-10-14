<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('organization admin sees role designation and can invite team members from dashboard', function () {
    Notification::fake();

    $user = User::factory()->create([
        'system_role' => 'customer',
        'account_type' => 'organization',
    ]);

    $orgTeam = Team::factory()->create([
        'name' => 'Blantyre Express Logistics',
        'is_personal' => false,
        'status' => 'approved',
        'created_by' => $user->id,
    ]);
    $orgTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($orgTeam);

    $this->actingAs($user->fresh());

    Livewire::test('dashboard-overview')
        ->assertSee('Organization Admin')
        ->assertSee('Blantyre Express Logistics')
        ->call('openInviteModal')
        ->set('inviteEmail', 'newdriver@blantyreexpress.mw')
        ->set('inviteRole', 'member')
        ->call('sendInvitation')
        ->assertHasNoErrors();

    $invitation = TeamInvitation::where('email', 'newdriver@blantyreexpress.mw')->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->team_id)->toBe($orgTeam->id);

    Notification::assertSentOnDemand(TeamInvitationNotification::class);
});

test('micromek staff sees staff designation and operations metrics on dashboard', function () {
    $staff = User::factory()->create([
        'system_role' => 'micromek_staff',
    ]);

    $team = Team::factory()->create(['created_by' => $staff->id]);
    $team->members()->attach($staff, ['role' => 'owner']);
    $staff->switchTeam($team);

    $this->actingAs($staff->fresh());

    Livewire::test('dashboard-overview')
        ->assertSee('MicroMek Operations Staff')
        ->assertSee('Managing GPS hardware dispatch')
        ->assertSee('Pending Hardware Requests')
        ->assertSee('Pending Organization Registrations');
});

test('individual customer sees personal fleet designation on dashboard', function () {
    $user = User::factory()->create([
        'system_role' => 'customer',
        'account_type' => 'individual',
    ]);

    $this->actingAs($user->fresh());

    Livewire::test('dashboard-overview')
        ->assertSee('Personal Fleet')
        ->assertSee('Private vehicle management')
        ->assertSee('My Registered Vehicles');
});
