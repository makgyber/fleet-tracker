<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team imported from the tbss daily field schedule. Each team maps to a trip
 * in the fleet-tracker; its team_uuid backs the QR code the driver app scans.
 */
class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_uuid',
        'code',
        'schedule_date',
        'tbss_schedule_id',
        'color',
        'vehicle_hint',
        'members',
        'vehicle_id',
        'imported_at',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'members' => 'array',
        'imported_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * The team's current trip (there is normally one per import).
     */
    public function currentTrip(): HasMany
    {
        return $this->hasMany(Trip::class)->latest();
    }
}
