<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'trip_id',
        'latitude',
        'longitude',
        'speed_mps',
        'heading_deg',
        'accuracy_m',
        'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'speed_mps' => 'float',
        'heading_deg' => 'float',
        'accuracy_m' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
