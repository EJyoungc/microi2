<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'vehicle_id',
        'latitude',
        'longitude',
        'speed',
        'heading',
        'altitude',
        'accuracy',
        'satellites',
        'battery',
        'raw_payload',
        'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'speed' => 'float',
        'heading' => 'float',
        'altitude' => 'float',
        'accuracy' => 'float',
        'battery' => 'float',
        'raw_payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
