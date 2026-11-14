<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * current_password:sanctum verifies the supplied value against the
     * authenticated user's stored hash via Laravel's Hash::check, using the
     * same guard that authenticated the request.
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * Provide a clear message when the current password is wrong.
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
