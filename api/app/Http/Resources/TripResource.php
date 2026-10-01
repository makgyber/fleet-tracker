<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'origin' => [
                'latitude' => $this->origin_latitude,
                'longitude' => $this->origin_longitude,
            ],
            'route' => [
                'total_distance_m' => $this->total_distance_m,
                'total_duration_s' => $this->total_duration_s,
                'geometry' => $this->route_geometry,
                'computed_at' => $this->route_computed_at,
            ],
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'stops' => TripStopResource::collection($this->whenLoaded('stops')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
