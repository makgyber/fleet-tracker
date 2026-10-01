<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDestinationRequest extends FormRequest
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
            'name' => [$req, 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => [$req, 'numeric', 'between:-90,90'],
            'longitude' => [$req, 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
