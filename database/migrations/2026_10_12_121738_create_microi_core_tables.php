<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Extend Users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('system_role')->default('customer')->after('email'); // super_admin, micromek_staff, customer
            $table->string('account_type')->default('individual')->after('system_role'); // individual, organization
            $table->string('phone_number')->nullable()->after('account_type');
            $table->string('physical_address')->nullable()->after('phone_number');
            $table->boolean('onboarding_completed')->default(false)->after('physical_address');
            $table->boolean('is_active')->default(true)->after('onboarding_completed');
        });

        // 2. Extend Teams (Organizations) table
        Schema::table('teams', function (Blueprint $table) {
            $table->string('status')->default('approved')->after('is_personal'); // pending, approved, rejected, suspended
            $table->string('org_type')->nullable()->after('status'); // corporate, ngo, government, logistics, other
            $table->string('registration_number')->nullable()->after('org_type');
            $table->string('contact_email')->nullable()->after('registration_number');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->text('business_address')->nullable()->after('contact_phone');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('business_address');
            $table->text('rejection_reason')->nullable()->after('created_by');
        });

        // 3. Vehicles table
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plate_number')->unique();
            $table->string('name');
            $table->string('make');
            $table->string('model');
            $table->string('year')->nullable();
            $table->string('category')->default('sedan'); // sedan, suv, truck, motorcycle, van, drone, other
            $table->string('color')->nullable();
            $table->text('operational_notes')->nullable();
            $table->string('status')->default('active'); // active, maintenance, decommissioned
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // 4. GPS Devices table
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique(); // Unique public/hardware identifier e.g., MEK-GPS-001
            $table->string('serial_number')->nullable()->unique();
            $table->string('model')->default('MicroMek-T100');
            $table->string('api_key_hash')->nullable(); // Hashed secret for authentication
            $table->string('api_key_prefix', 16)->nullable(); // Plain prefix for quick lookup
            $table->string('provisioning_status')->default('unprovisioned'); // unprovisioned, provisioned, active, revoked
            $table->string('status')->default('active'); // active, inactive, maintenance
            $table->timestamp('last_seen_at')->nullable();
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->float('last_speed')->nullable();
            $table->float('last_heading')->nullable();
            $table->float('last_altitude')->nullable();
            $table->float('battery_level')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('device_id');
            $table->index('api_key_prefix');
            $table->index('last_seen_at');
        });

        // 5. Device Vehicle Assignments table (Historical & Active)
        Schema::create('device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->string('status')->default('active'); // active, terminated
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['vehicle_id', 'status']);
        });

        // 6. Device Requests table
        Schema::create('device_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('status')->default('submitted'); // submitted, under_review, approved, rejected, awaiting_device, assigned, cancelled
            $table->text('reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // 7. Location Histories table (Append-only GPS Telemetry)
        Schema::create('location_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('speed')->nullable(); // km/h
            $table->float('heading')->nullable(); // degrees
            $table->float('altitude')->nullable(); // meters
            $table->float('accuracy')->nullable();
            $table->integer('satellites')->nullable();
            $table->float('battery')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['device_id', 'recorded_at']);
            $table->index(['vehicle_id', 'recorded_at']);
        });

        // 8. Audit Logs table
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // e.g., org.approved, device.assigned, vehicle.created
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('location_histories');
        Schema::dropIfExists('device_requests');
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('vehicles');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'status',
                'org_type',
                'registration_number',
                'contact_email',
                'contact_phone',
                'business_address',
                'created_by',
                'rejection_reason',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'system_role',
                'account_type',
                'phone_number',
                'physical_address',
                'onboarding_completed',
                'is_active',
            ]);
        });
    }
};
