<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'serial_number',
        'model',
        'api_key_hash',
        'api_key_prefix',
        'provisioning_status',
        'status',
        'last_seen_at',
        'last_latitude',
        'last_longitude',
        'last_speed',
        'last_heading',
        'last_altitude',
        'battery_level',
    ];

    protected $hidden = [
        'api_key_hash',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_latitude' => 'float',
        'last_longitude' => 'float',
        'last_speed' => 'float',
        'last_heading' => 'float',
        'last_altitude' => 'float',
        'battery_level' => 'float',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(DeviceAssignment::class)->where('status', 'active')->latestOfMany();
    }

    public function locationHistories(): HasMany
    {
        return $this->hasMany(LocationHistory::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->greaterThan(now()->subMinutes(10));
    }
}
