<?php

namespace App\Http\Requests;

use App\Models\TicketComment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for posting or editing a comment on a ticket.
 *
 * Access is enforced by the controller (TicketPolicy when posting,
 * TicketCommentPolicy when editing); here we only validate the payload and
 * stamp the author. `content` stays required on every method: it is the only
 * editable field, so an edit that leaves it out has nothing to say.
 */
class TicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // Editing is judged against the comment here, before validation, so
        // the answer to "may I touch this comment" never depends on how
        // complete the body was. Posting stays gated by the ticket, which the
        // controller checks.
        return ($comment = $this->routeComment())
            ? $user->can('update', $comment)
            : true;
    }

    /**
     * The author and parent ticket are derived, never taken from the body.
     *
     * Editing keeps both as they were: a comment does not change tickets, and
     * a project administrator fixing someone else's comment must not end up
     * recorded as the person who wrote it.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        if ($comment = $this->routeComment()) {
            $data['ticket_id'] = $comment->ticket_id;
            $data['user_id'] = $comment->user_id;
        } else {
            if ($ticket = $this->route('ticket')) {
                $data['ticket_id'] = is_object($ticket) ? $ticket->getKey() : $ticket;
            }

            if ($this->user()) {
                $data['user_id'] = $this->user()->getKey();
            }
        }

        if ($data) {
            $this->merge($data);
        }
    }

    /**
     * The comment being edited, when the request went through a route that
     * binds one. Null when posting a new one.
     */
    protected function routeComment(): ?TicketComment
    {
        $comment = $this->route('comment');

        return $comment instanceof TicketComment ? $comment : null;
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
