<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'registration',
        'make',
        'model',
        'driver_id',
        'status',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PositionHistory::class);
    }

    /**
     * The vehicle's current active trip, if any.
     */
    public function activeTrip(): HasMany
    {
        return $this->hasMany(Trip::class)->whereIn('status', ['optimized', 'in_progress']);
    }
}
