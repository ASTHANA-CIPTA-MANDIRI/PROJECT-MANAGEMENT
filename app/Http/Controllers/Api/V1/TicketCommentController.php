<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\TicketCommentRequest;
use App\Http\Resources\TicketCommentResource;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Http\Request;

class TicketCommentController extends ApiController
{
    /**
     * GET /api/v1/tickets/{ticket}/comments
     *
     * Lists comments of a ticket the user may view. Supports ?sort, ?per_page.
     */
    public function index(Request $request, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $query = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->with('user');

        $this->applySorting($query, $request, ['created_at', 'updated_at'], '-created_at');

        return TicketCommentResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * POST /api/v1/tickets/{ticket}/comments
     *
     * ticket_id and author are stamped by TicketCommentRequest.
     */
    public function store(TicketCommentRequest $request, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $comment = TicketComment::create($request->validated());

        return (new TicketCommentResource($comment->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT|PATCH /api/v1/comments/{comment}
     *
     * Only `content` is editable; the author and the parent ticket are kept as
     * they were. TicketCommentPolicy limits this to the author and the
     * project's administrators.
     */
    public function update(TicketCommentRequest $request, TicketComment $comment)
    {
        $this->authorize('update', $comment);

        $comment->update($request->validated());

        return new TicketCommentResource($comment->load('user'));
    }

    /**
     * DELETE /api/v1/comments/{comment}
     */
    public function destroy(TicketComment $comment)
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->noContent();
    }
}
