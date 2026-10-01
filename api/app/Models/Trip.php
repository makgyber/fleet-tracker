<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'team_id',
        'reference',
        'status',
        'origin_latitude',
        'origin_longitude',
        'total_distance_m',
        'total_duration_s',
        'route_geometry',
        'route_computed_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'origin_latitude' => 'float',
        'origin_longitude' => 'float',
        'total_distance_m' => 'float',
        'total_duration_s' => 'float',
        'route_computed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Stops in optimized visiting order (falls back to requested order
     * before optimization has run).
     */
    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)
            ->orderByRaw('sequence IS NULL')       // non-null sequences first
            ->orderBy('sequence')
            ->orderBy('requested_order');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PositionHistory::class);
    }
}
