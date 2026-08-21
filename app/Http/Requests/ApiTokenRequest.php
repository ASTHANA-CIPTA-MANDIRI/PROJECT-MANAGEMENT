<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules for issuing a personal access token.
 *
 * There is no permission to check: a token can never do more than the user it
 * belongs to, so every authenticated user may manage their own. Who is allowed
 * to *issue* one is decided in the controller, which turns away requests that
 * are themselves authenticated with a token.
 */
class ApiTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a label, shown back in the panel so a token can be told
            // apart from the others before it is revoked.
            'name' => ['required', 'string', 'max:255'],

            // Optional shorter life. The global window
            // (config('sanctum.expiration')) still caps it — the controller
            // clamps the date, so this can only bring the expiry forward.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
