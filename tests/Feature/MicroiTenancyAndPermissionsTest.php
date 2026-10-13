<?php

use App\Enums\TeamRole;
use App\Models\Device;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Livewire\Livewire;

beforeEach(function () {
    // Org A setup
    $this->orgAOwner = User::factory()->create(['system_role' => 'customer']);
    $this->teamA = Team::factory()->create(['name' => 'Organization Alpha', 'is_personal' => false, 'status' => 'approved']);
    $this->teamA->members()->attach($this->orgAOwner, ['role' => TeamRole::Owner->value]);
    $this->orgAOwner->update(['current_team_id' => $this->teamA->id]);

    $this->vehicleA = Vehicle::create([
        'team_id' => $this->teamA->id,
        'user_id' => $this->orgAOwner->id,
        'plate_number' => 'ORG-A-101',
        'name' => 'Alpha Truck',
        'make' => 'Isuzu',
        'model' => 'Forward',
        'status' => 'active',
    ]);

    // Org B setup
    $this->orgBOwner = User::factory()->create(['system_role' => 'customer']);
    $this->teamB = Team::factory()->create(['name' => 'Organization Beta', 'is_personal' => false, 'status' => 'approved']);
    $this->teamB->members()->attach($this->orgBOwner, ['role' => TeamRole::Owner->value]);
    $this->orgBOwner->update(['current_team_id' => $this->teamB->id]);

    $this->vehicleB = Vehicle::create([
        'team_id' => $this->teamB->id,
        'user_id' => $this->orgBOwner->id,
        'plate_number' => 'ORG-B-202',
        'name' => 'Beta Van',
        'make' => 'Toyota',
        'model' => 'HiAce',
        'status' => 'active',
    ]);

    // MicroMek staff setup
    $this->staffUser = User::factory()->create(['system_role' => 'micromek_staff']);
    $this->staffTeam = Team::factory()->create(['is_personal' => true]);
    $this->staffTeam->members()->attach($this->staffUser, ['role' => TeamRole::Owner->value]);
    $this->staffUser->update(['current_team_id' => $this->staffTeam->id]);
});

test('organization A only sees its own vehicles in vehicle management', function () {
    $this->actingAs($this->orgAOwner);

    Livewire::test('vehicles.vehicles-livewire')
        ->assertSee('ORG-A-101')
        ->assertDontSee('ORG-B-202');
});

test('organization B cannot view or edit organization A vehicle', function () {
    $this->actingAs($this->orgBOwner);

    Livewire::test('vehicles.vehicles-livewire')
        ->call('openEditModal', $this->vehicleA->id)
        ->assertForbidden();
});

test('micromek staff can access cross tenant vehicles', function () {
    $this->actingAs($this->staffUser);

    Livewire::test('vehicles.vehicles-livewire')
        ->assertSee('ORG-A-101')
        ->assertSee('ORG-B-202');
});

test('devices can be assigned and unassigned cleanly without conflict', function () {
    $this->actingAs($this->staffUser);

    $device = Device::create([
        'device_id' => 'DEV-ASSIGN-01',
        'model' => 'T100',
        'status' => 'active',
    ]);

    Livewire::test('admin.devices-livewire')
        ->set('selectedDeviceId', $device->id)
        ->set('selectedVehicleId', $this->vehicleA->id)
        ->call('assignDevice')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('device_assignments', [
        'device_id' => $device->id,
        'vehicle_id' => $this->vehicleA->id,
        'status' => 'active',
    ]);

    // Reassigning to Vehicle B terminates previous assignment
    Livewire::test('admin.devices-livewire')
        ->set('selectedDeviceId', $device->id)
        ->set('selectedVehicleId', $this->vehicleB->id)
        ->call('assignDevice')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('device_assignments', [
        'device_id' => $device->id,
        'vehicle_id' => $this->vehicleA->id,
        'status' => 'terminated',
    ]);

    $this->assertDatabaseHas('device_assignments', [
        'device_id' => $device->id,
        'vehicle_id' => $this->vehicleB->id,
        'status' => 'active',
    ]);
});
