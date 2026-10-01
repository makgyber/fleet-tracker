<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'destination_id' => $this->destination_id,
            'requested_order' => $this->requested_order,
            'sequence' => $this->sequence,
            'leg_distance_m' => $this->leg_distance_m,
            'leg_duration_s' => $this->leg_duration_s,
            'eta' => $this->eta,
            'status' => $this->status,
            'arrived_at' => $this->arrived_at,
            'destination' => new DestinationResource($this->whenLoaded('destination')),
        ];
    }
}
