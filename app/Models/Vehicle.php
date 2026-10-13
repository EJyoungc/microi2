<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'user_id',
        'plate_number',
        'name',
        'make',
        'model',
        'year',
        'category',
        'color',
        'operational_notes',
        'status',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function activeAssignment(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class)->where('status', 'active');
    }

    public function currentAssignment()
    {
        return $this->hasOne(DeviceAssignment::class)->where('status', 'active')->latestOfMany();
    }

    public function deviceRequests(): HasMany
    {
        return $this->hasMany(DeviceRequest::class);
    }

    public function locationHistories(): HasMany
    {
        return $this->hasMany(LocationHistory::class);
    }
}
