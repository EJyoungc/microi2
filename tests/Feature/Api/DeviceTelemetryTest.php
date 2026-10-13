<?php

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\LocationHistory;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->plainKey = 'SECRET_TEST_KEY_123';
    $this->device = Device::create([
        'device_id' => 'DEV-TEST-001',
        'serial_number' => 'SN-TEST-001',
        'model' => 'EcoTracker-Pro',
        'api_key_hash' => Hash::make($this->plainKey),
        'api_key_prefix' => substr($this->plainKey, 0, 8),
        'provisioning_status' => 'active',
        'status' => 'active',
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'TEST-PLATE-1',
        'name' => 'Field Rover 1',
        'make' => 'Toyota',
        'model' => 'Land Cruiser',
        'status' => 'active',
    ]);

    DeviceAssignment::create([
        'device_id' => $this->device->id,
        'vehicle_id' => $this->vehicle->id,
        'assigned_at' => now()->subDay(),
        'status' => 'active',
    ]);
});

test('authenticated device can submit valid telemetry', function () {
    $response = $this->postJson(route('api.v1.device.telemetry'), [
        'device_id' => 'DEV-TEST-001',
        'api_key' => 'SECRET_TEST_KEY_123',
        'latitude' => -15.786111,
        'longitude' => 35.005833,
        'speed' => 45.0,
        'heading' => 90.0,
        'altitude' => 1050.0,
        'battery' => 95.0,
        'recorded_at' => now()->toIso8601String(),
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'assigned_vehicle_id' => $this->vehicle->id,
        ]);

    $this->assertDatabaseHas('location_histories', [
        'device_id' => $this->device->id,
        'vehicle_id' => $this->vehicle->id,
        'latitude' => -15.786111,
        'longitude' => 35.005833,
    ]);

    $freshDevice = $this->device->fresh();
    expect($freshDevice->last_latitude)->toEqual(-15.786111);
    expect($freshDevice->last_longitude)->toEqual(35.005833);
});

test('device with invalid api key is rejected with 401', function () {
    $response = $this->postJson(route('api.v1.device.telemetry'), [
        'device_id' => 'DEV-TEST-001',
        'api_key' => 'WRONG_KEY',
        'latitude' => -15.786111,
        'longitude' => 35.005833,
    ]);

    $response->assertStatus(401);
});

test('inactive or suspended device cannot submit telemetry', function () {
    $this->device->update(['status' => 'inactive']);

    $response = $this->postJson(route('api.v1.device.telemetry'), [
        'device_id' => 'DEV-TEST-001',
        'api_key' => 'SECRET_TEST_KEY_123',
        'latitude' => -15.786111,
        'longitude' => 35.005833,
    ]);

    $response->assertStatus(401);
});

test('invalid coordinates are rejected with 422 validation error', function () {
    $response = $this->postJson(route('api.v1.device.telemetry'), [
        'device_id' => 'DEV-TEST-001',
        'api_key' => 'SECRET_TEST_KEY_123',
        'latitude' => 999.99, // invalid latitude
        'longitude' => 35.005833,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['latitude']);
});

test('out of order older telemetry does not overwrite newer latest position', function () {
    // 1. Submit newest position
    $newerTime = now();
    $this->postJson(route('api.v1.device.telemetry'), [
        'device_id' => 'DEV-TEST-001',
        'api_key' => 'SECRET_TEST_KEY_123',
        'latitude' => -15.780000,
        'longitude' => 35.000000,
        'recorded_at' => $newerTime->toIso8601String(),
    ])->assertOk();

    // 2. Submit older delayed position
    $olderTime = now()->subHour();
    $this->postJson(route('api.v1.device.telemetry'), [
        'device_id' => 'DEV-TEST-001',
        'api_key' => 'SECRET_TEST_KEY_123',
        'latitude' => -16.000000,
        'longitude' => 34.000000,
        'recorded_at' => $olderTime->toIso8601String(),
    ])->assertOk();

    // History should contain both
    expect(LocationHistory::where('device_id', $this->device->id)->count())->toBe(2);

    // Latest on device must remain the newer position
    $fresh = $this->device->fresh();
    expect($fresh->last_latitude)->toEqual(-15.780000);
    expect($fresh->last_longitude)->toEqual(35.000000);
});
