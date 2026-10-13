<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\LocationHistory;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MicroiDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. MicroMek System Administrator
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@micromek.net'],
            [
                'name' => 'MicroMek System Admin',
                'password' => Hash::make('password'),
                'system_role' => 'super_admin',
                'account_type' => 'individual',
                'phone_number' => '+265 996 409 210',
                'physical_address' => 'Blantyre, Malawi',
                'onboarding_completed' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $adminTeam = Team::firstOrCreate(
            ['slug' => 'micromek-admin-fleet'],
            [
                'name' => 'MicroMek Operations HQ',
                'is_personal' => true,
                'status' => 'approved',
                'created_by' => $superAdmin->id,
            ]
        );

        $adminTeam->members()->syncWithoutDetaching([
            $superAdmin->id => ['role' => TeamRole::Owner->value],
        ]);
        $superAdmin->update(['current_team_id' => $adminTeam->id]);

        // 2. MicroMek Staff
        $staffUser = User::firstOrCreate(
            ['email' => 'staff@micromek.net'],
            [
                'name' => 'Elijah Kawinga',
                'password' => Hash::make('password'),
                'system_role' => 'micromek_staff',
                'account_type' => 'individual',
                'phone_number' => '+265 888 123 456',
                'physical_address' => 'Blantyre, Malawi',
                'onboarding_completed' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $staffTeam = Team::firstOrCreate(
            ['slug' => 'micromek-staff-fleet'],
            [
                'name' => 'MicroMek Engineering Team',
                'is_personal' => true,
                'status' => 'approved',
                'created_by' => $staffUser->id,
            ]
        );

        $staffTeam->members()->syncWithoutDetaching([
            $staffUser->id => ['role' => TeamRole::Owner->value],
        ]);
        $staffUser->update(['current_team_id' => $staffTeam->id]);

        // 3. Organization Customer (e.g. Apex Logistics Malawi)
        $orgOwner = User::firstOrCreate(
            ['email' => 'manager@apexlogistics.mw'],
            [
                'name' => 'Apex Operations Manager',
                'password' => Hash::make('password'),
                'system_role' => 'customer',
                'account_type' => 'organization',
                'phone_number' => '+265 991 223 344',
                'physical_address' => 'Lilongwe Industrial Area, Malawi',
                'onboarding_completed' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $orgTeam = Team::firstOrCreate(
            ['slug' => 'apex-logistics-malawi'],
            [
                'name' => 'Apex Logistics Malawi',
                'is_personal' => false,
                'status' => 'approved',
                'org_type' => 'logistics',
                'registration_number' => 'MW-LOG-2024-88',
                'contact_email' => 'operations@apexlogistics.mw',
                'contact_phone' => '+265 991 223 344',
                'business_address' => 'Plot 42, Area 4, Lilongwe, Malawi',
                'created_by' => $orgOwner->id,
            ]
        );

        $orgTeam->members()->syncWithoutDetaching([
            $orgOwner->id => ['role' => TeamRole::Owner->value],
        ]);

        $orgOwner->update(['current_team_id' => $orgTeam->id]);

        // 4. Individual Customer
        $individual = User::firstOrCreate(
            ['email' => 'customer@gmail.com'],
            [
                'name' => 'Chisomo Banda',
                'password' => Hash::make('password'),
                'system_role' => 'customer',
                'account_type' => 'individual',
                'phone_number' => '+265 999 555 777',
                'physical_address' => 'Chichiri, Blantyre, Malawi',
                'onboarding_completed' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Individual personal team
        $indTeam = Team::firstOrCreate(
            ['slug' => 'chisomo-personal'],
            [
                'name' => "Chisomo's Vehicles",
                'is_personal' => true,
                'status' => 'approved',
                'created_by' => $individual->id,
            ]
        );

        $indTeam->members()->syncWithoutDetaching([
            $individual->id => ['role' => TeamRole::Owner->value],
        ]);

        $individual->update(['current_team_id' => $indTeam->id]);

        // 5. GPS Devices
        $apiKey1 = 'MEK_SECRET_KEY_ALPHA_01';
        $device1 = Device::firstOrCreate(
            ['device_id' => 'MEK-GPS-101'],
            [
                'serial_number' => 'SN-MEK-8801',
                'model' => 'EcoTracker-Pro',
                'api_key_hash' => Hash::make($apiKey1),
                'api_key_prefix' => substr($apiKey1, 0, 8),
                'provisioning_status' => 'active',
                'status' => 'active',
                'last_seen_at' => now(),
                'last_latitude' => -15.786111,
                'last_longitude' => 35.005833,
                'last_speed' => 45.2,
                'last_heading' => 120.0,
                'last_altitude' => 1040.0,
                'battery_level' => 96.0,
            ]
        );

        $apiKey2 = 'MEK_SECRET_KEY_BETA_02';
        $device2 = Device::firstOrCreate(
            ['device_id' => 'MEK-GPS-102'],
            [
                'serial_number' => 'SN-MEK-8802',
                'model' => 'EcoTracker-Mini',
                'api_key_hash' => Hash::make($apiKey2),
                'api_key_prefix' => substr($apiKey2, 0, 8),
                'provisioning_status' => 'active',
                'status' => 'active',
                'last_seen_at' => now()->subMinutes(3),
                'last_latitude' => -15.813680,
                'last_longitude' => 35.006646,
                'last_speed' => 32.5,
                'last_heading' => 45.0,
                'last_altitude' => 1025.0,
                'battery_level' => 88.0,
            ]
        );

        $device3 = Device::firstOrCreate(
            ['device_id' => 'MEK-GPS-103'],
            [
                'serial_number' => 'SN-MEK-8803',
                'model' => 'EcoTracker-Pro',
                'provisioning_status' => 'provisioned',
                'status' => 'active',
            ]
        );

        // 6. Vehicles
        $vehicleOrg = Vehicle::firstOrCreate(
            ['plate_number' => 'BW 4492'],
            [
                'team_id' => $orgTeam->id,
                'user_id' => $orgOwner->id,
                'name' => 'Apex Delivery Van 1',
                'make' => 'Toyota',
                'model' => 'HiAce',
                'year' => '2022',
                'category' => 'van',
                'color' => 'White',
                'operational_notes' => 'Medical courier route Blantyre - Zomba',
                'status' => 'active',
            ]
        );

        $vehicleInd = Vehicle::firstOrCreate(
            ['plate_number' => 'BT 8901'],
            [
                'team_id' => $indTeam->id,
                'user_id' => $individual->id,
                'name' => 'Chisomo Personal SUV',
                'make' => 'Toyota',
                'model' => 'RAV4',
                'year' => '2020',
                'category' => 'suv',
                'color' => 'Silver',
                'status' => 'active',
            ]
        );

        // 7. Active Device Assignments
        DeviceAssignment::firstOrCreate(
            [
                'device_id' => $device1->id,
                'vehicle_id' => $vehicleOrg->id,
                'status' => 'active',
            ],
            [
                'assigned_by' => $superAdmin->id,
                'assigned_at' => now()->subDays(10),
                'notes' => 'Installed at Apex Blantyre depot',
            ]
        );

        DeviceAssignment::firstOrCreate(
            [
                'device_id' => $device2->id,
                'vehicle_id' => $vehicleInd->id,
                'status' => 'active',
            ],
            [
                'assigned_by' => $staffUser->id,
                'assigned_at' => now()->subDays(5),
                'notes' => 'Customer self-install verified',
            ]
        );

        // 8. Sample Telemetry History for Today
        $points = [
            [-15.820000, 35.002000, 20.0, 40.0],
            [-15.815000, 35.004000, 35.0, 45.0],
            [-15.810000, 35.005000, 42.0, 50.0],
            [-15.800000, 35.006000, 55.0, 60.0],
            [-15.790000, 35.005500, 50.0, 80.0],
            [-15.786111, 35.005833, 45.2, 120.0],
        ];

        foreach ($points as $index => $pt) {
            LocationHistory::create([
                'device_id' => $device1->id,
                'vehicle_id' => $vehicleOrg->id,
                'latitude' => $pt[0],
                'longitude' => $pt[1],
                'speed' => $pt[2],
                'heading' => $pt[3],
                'altitude' => 1040 - $index * 5,
                'accuracy' => 3.5,
                'satellites' => 12,
                'battery' => 96.0,
                'recorded_at' => now()->subMinutes(60 - $index * 10),
            ]);
        }
    }
}
