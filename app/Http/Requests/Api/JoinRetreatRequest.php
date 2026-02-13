<?php

namespace App\Http\Requests\Api;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class JoinRetreatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|min:4|max:12',
            'auth_mode' => 'nullable|string|in:join,signin',
            'name' => 'required_unless:auth_mode,signin|string|min:2|max:50',
            'phone_number' => ['required', 'string', 'max:24', 'regex:/^\+[1-9]\d{7,14}$/'],
            'gender' => 'nullable|string|in:male,female',
            'vehicle_color' => 'nullable|string|max:30',
            'vehicle_description' => 'nullable|string|max:50',
            'expo_push_token' => 'nullable|string|max:255',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = PhoneNumber::normalize($this->input('phone_number'));

        $merge = [];

        if ($normalized) {
            $merge['phone_number'] = $normalized;
        }

        if ($this->filled('auth_mode')) {
            $merge['auth_mode'] = strtolower(trim((string) $this->input('auth_mode')));
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
