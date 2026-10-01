<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'destination_id',
        'requested_order',
        'sequence',
        'leg_distance_m',
        'leg_duration_s',
        'eta',
        'status',
        'arrived_at',
    ];

    protected $casts = [
        'requested_order' => 'integer',
        'sequence' => 'integer',
        'leg_distance_m' => 'float',
        'leg_duration_s' => 'float',
        'eta' => 'datetime',
        'arrived_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
