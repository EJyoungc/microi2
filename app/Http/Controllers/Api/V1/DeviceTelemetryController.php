<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\LocationHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class DeviceTelemetryController extends Controller
{
    /**
     * Ingest telemetry from a GPS device.
     */
    public function ingest(Request $request): JsonResponse
    {
        // 1. Validate payload structure
        $validator = Validator::make($request->all(), [
            'device_id' => ['required', 'string', 'max:64'],
            'api_key' => ['required', 'string', 'max:128'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => ['nullable', 'date'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'altitude' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric'],
            'satellites' => ['nullable', 'integer', 'min:0'],
            'battery' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // 2. Authenticate device
        $device = Device::where('device_id', $validated['device_id'])
            ->where('status', 'active')
            ->first();

        if (! $device || ! $device->api_key_hash) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device unauthorized or inactive.',
            ], 401);
        }

        if (! Hash::check($validated['api_key'], $device->api_key_hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid device credentials.',
            ], 401);
        }

        // 3. Resolve active vehicle assignment (if any)
        $activeAssignment = $device->currentAssignment;
        $vehicleId = $activeAssignment ? $activeAssignment->vehicle_id : null;

        $recordedAt = isset($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'])
            : now();

        // 4. Atomic transaction: append location history & update latest device location
        DB::transaction(function () use ($device, $vehicleId, $validated, $recordedAt) {
            // Append-only history record
            LocationHistory::create([
                'device_id' => $device->id,
                'vehicle_id' => $vehicleId,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'speed' => $validated['speed'] ?? null,
                'heading' => $validated['heading'] ?? null,
                'altitude' => $validated['altitude'] ?? null,
                'accuracy' => $validated['accuracy'] ?? null,
                'satellites' => $validated['satellites'] ?? null,
                'battery' => $validated['battery'] ?? null,
                'raw_payload' => $validated,
                'recorded_at' => $recordedAt,
            ]);

            // Update latest known state on device (protect against out-of-order older messages)
            if (! $device->last_seen_at || $recordedAt->greaterThanOrEqualTo($device->last_seen_at)) {
                $device->update([
                    'last_seen_at' => $recordedAt,
                    'last_latitude' => $validated['latitude'],
                    'last_longitude' => $validated['longitude'],
                    'last_speed' => $validated['speed'] ?? $device->last_speed,
                    'last_heading' => $validated['heading'] ?? $device->last_heading,
                    'last_altitude' => $validated['altitude'] ?? $device->last_altitude,
                    'battery_level' => $validated['battery'] ?? $device->battery_level,
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Telemetry ingested successfully.',
            'assigned_vehicle_id' => $vehicleId,
            'recorded_at' => $recordedAt->toIso8601String(),
        ], 200);
    }

    /**
     * Optional device heartbeat endpoint.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
            'battery' => ['nullable', 'numeric'],
        ]);

        $device = Device::where('device_id', $request->device_id)->first();

        if (! $device || ! Hash::check($request->api_key, $device->api_key_hash)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $device->update([
            'last_seen_at' => now(),
            'battery_level' => $request->battery ?? $device->battery_level,
        ]);

        return response()->json([
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
