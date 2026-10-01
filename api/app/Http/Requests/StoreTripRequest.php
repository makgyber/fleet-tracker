<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'vehicle_id' => [$req, 'integer', 'exists:vehicles,id'],
            'driver_id' => [$req, 'integer', 'exists:drivers,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['planned', 'optimized', 'in_progress', 'completed', 'cancelled'])],
            'origin_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'origin_longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // Destinations may be supplied inline when creating a trip.
            'destination_ids' => ['sometimes', 'array'],
            'destination_ids.*' => ['integer', 'exists:destinations,id'],
        ];
    }
}
