<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $driverId = $this->route('driver')?->id;

        return [
            'name' => [$req, 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'firebase_uid' => [
                'nullable', 'string', 'max:255',
                Rule::unique('drivers', 'firebase_uid')->ignore($driverId),
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ];
    }
}
