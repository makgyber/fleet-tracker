<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $vehicleId = $this->route('vehicle')?->id;

        return [
            'label' => [$req, 'string', 'max:255'],
            'registration' => [
                $req, 'string', 'max:40',
                Rule::unique('vehicles', 'registration')->ignore($vehicleId),
            ],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'status' => ['sometimes', Rule::in(['available', 'on_trip', 'maintenance', 'offline'])],
        ];
    }
}
