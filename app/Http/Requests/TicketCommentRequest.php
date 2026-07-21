<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for posting a comment to a ticket.
 *
 * Access to the parent ticket is enforced by the controller (TicketPolicy);
 * here we only validate the payload and stamp the author.
 */
class TicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The author and parent ticket are derived, never taken from the body.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        if ($ticket = $this->route('ticket')) {
            $data['ticket_id'] = is_object($ticket) ? $ticket->getKey() : $ticket;
        }

        if ($this->user()) {
            $data['user_id'] = $this->user()->getKey();
        }

        if ($data) {
            $this->merge($data);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            // Stamped by prepareForValidation; listed here so validated()
            // returns them and they cannot be spoofed from the body.
            'ticket_id' => ['required', Rule::exists('tickets', 'id')->whereNull('deleted_at')],
            'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }
}
