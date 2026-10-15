<?php

use App\Models\Device;
use App\Models\LocationHistory;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('history playback page renders and displays date selector with recorded dates', function () {
    $user = User::factory()->create([
        'system_role' => 'customer',
        'account_type' => 'individual',
    ]);

    $team = Team::factory()->personal()->create(['created_by' => $user->id]);
    $team->members()->attach($user, ['role' => 'owner']);
    $user->switchTeam($team);

    $vehicle = Vehicle::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plate_number' => 'BT-1020',
        'name' => 'Toyota Hilux',
        'make' => 'Toyota',
        'model' => 'Hilux',
        'status' => 'active',
    ]);

    $device = Device::create([
        'device_id' => 'MEK-TEST-01',
        'api_key' => 'MEK_TEST_KEY_1234567890123456',
        'status' => 'active',
    ]);

    // Create 2 pings on Oct 12, 2026 and 1 ping on Oct 14, 2026
    LocationHistory::create([
        'device_id' => $device->id,
        'vehicle_id' => $vehicle->id,
        'latitude' => -15.786111,
        'longitude' => 35.005833,
        'speed' => 45.5,
        'heading' => 90.0,
        'altitude' => 1050.0,
        'recorded_at' => Carbon::parse('2026-10-12 10:00:00'),
    ]);
    LocationHistory::create([
        'device_id' => $device->id,
        'vehicle_id' => $vehicle->id,
        'latitude' => -15.787111,
        'longitude' => 35.006833,
        'speed' => 50.0,
        'heading' => 95.0,
        'altitude' => 1052.0,
        'recorded_at' => Carbon::parse('2026-10-12 10:02:00'),
    ]);
    LocationHistory::create([
        'device_id' => $device->id,
        'vehicle_id' => $vehicle->id,
        'latitude' => -15.788111,
        'longitude' => 35.007833,
        'speed' => 52.0,
        'heading' => 100.0,
        'altitude' => 1055.0,
        'recorded_at' => Carbon::parse('2026-10-14 15:30:00'),
    ]);

    $this->actingAs($user->fresh());

    $this->get(route('history', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertSee('Historical Journey Playback')
        ->assertSee('Recorded Journey Date');

    $component = Livewire::test('tracking.history-map-livewire')
        ->assertSet('selectedVehicleId', $vehicle->id)
        ->assertSet('selectedDate', '2026-10-14');

    expect($component->get('availableDates'))->toHaveCount(2);

    // Select earlier date and check update
    $component->set('selectedDate', '2026-10-12')
        ->assertDispatched('historyDataUpdated');

    expect($component->get('historyPoints'))->toHaveCount(2);
});

test('history playback exportCsv streams valid CSV containing telemetry pings', function () {
    $user = User::factory()->create([
        'system_role' => 'customer',
        'account_type' => 'individual',
    ]);

    $team = Team::factory()->personal()->create(['created_by' => $user->id]);
    $team->members()->attach($user, ['role' => 'owner']);
    $user->switchTeam($team);

    $vehicle = Vehicle::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plate_number' => 'BT-5566',
        'name' => 'Nissan Navara',
        'make' => 'Nissan',
        'model' => 'Navara',
        'status' => 'active',
    ]);

    $device = Device::create([
        'device_id' => 'MEK-EXPORT-01',
        'api_key' => 'MEK_EXPORT_KEY_123456789012',
        'status' => 'active',
    ]);

    LocationHistory::create([
        'device_id' => $device->id,
        'vehicle_id' => $vehicle->id,
        'latitude' => -15.813734,
        'longitude' => 35.006271,
        'speed' => 60.5,
        'heading' => 120.0,
        'altitude' => 1020.0,
        'recorded_at' => Carbon::parse('2026-10-15 08:30:00'),
    ]);

    $this->actingAs($user->fresh());

    $component = Livewire::test('tracking.history-map-livewire')
        ->set('selectedVehicleId', $vehicle->id)
        ->set('selectedDate', '2026-10-15')
        ->call('exportCsv')
        ->assertFileDownloaded('telemetry_bt-5566_2026-10-15.csv');

    $content = base64_decode($component->effects['download']['content']);

    expect($content)->toContain('Ping #')
        ->and($content)->toContain('Speed (km/h)')
        ->and($content)->toContain('-15.813734')
        ->and($content)->toContain('35.006271')
        ->and($content)->toContain('MEK-EXPORT-01');
});

test('history playback exportJson streams valid JSON containing telemetry pings', function () {
    $user = User::factory()->create([
        'system_role' => 'customer',
        'account_type' => 'individual',
    ]);

    $team = Team::factory()->personal()->create(['created_by' => $user->id]);
    $team->members()->attach($user, ['role' => 'owner']);
    $user->switchTeam($team);

    $vehicle = Vehicle::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'plate_number' => 'BT-7788',
        'name' => 'Ford Ranger',
        'make' => 'Ford',
        'model' => 'Ranger',
        'status' => 'active',
    ]);

    $device = Device::create([
        'device_id' => 'MEK-JSON-01',
        'api_key' => 'MEK_JSON_KEY_123456789012',
        'status' => 'active',
    ]);

    LocationHistory::create([
        'device_id' => $device->id,
        'vehicle_id' => $vehicle->id,
        'latitude' => -15.790000,
        'longitude' => 35.010000,
        'speed' => 40.0,
        'heading' => 85.0,
        'altitude' => 1010.0,
        'recorded_at' => Carbon::parse('2026-10-15 09:15:00'),
    ]);

    $this->actingAs($user->fresh());

    $component = Livewire::test('tracking.history-map-livewire')
        ->set('selectedVehicleId', $vehicle->id)
        ->set('selectedDate', '2026-10-15')
        ->call('exportJson')
        ->assertFileDownloaded('telemetry_bt-7788_2026-10-15.json');

    $content = base64_decode($component->effects['download']['content']);
    $json = json_decode($content, true);

    expect($json)->toBeArray()
        ->and($json['vehicle']['plate_number'])->toBe('BT-7788')
        ->and($json['records'])->toHaveCount(1)
        ->and($json['records'][0]['latitude'])->toBe(-15.79)
        ->and($json['records'][0]['device_id'])->toBe('MEK-JSON-01');
});
