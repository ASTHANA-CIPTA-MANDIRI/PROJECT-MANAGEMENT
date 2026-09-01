<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    /**
     * Stream a media file, having first checked that the requesting user is
     * authorized to view the model it's attached to (a ticket attachment
     * requires TicketPolicy::view($ticket), a project cover requires
     * ProjectPolicy::view($project), etc). Media used to be served straight
     * off the public disk/symlink, bypassing that check entirely.
     */
    public function show(Media $media): StreamedResponse
    {
        $model = $media->model;

        abort_if($model === null, 404);
        abort_unless(Gate::allows('view', $model), 403);

        // Uploads are restricted to a raster-image whitelist or (for ticket
        // attachments) a document whitelist that excludes html/svg - see
        // config('system.images') / config('system.tickets.attachments').
        // This is the second line of defense for whatever predates those
        // rules or reaches the disk some other way: anything outside the
        // safe-to-render-inline image types downloads instead of executing
        // in the browser, and nosniff stops the browser from second-guessing
        // a mislabeled Content-Type.
        $disposition = in_array($media->mime_type, config('system.images.accepted_mime_types'), true)
            ? 'inline'
            : 'attachment';

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            ['X-Content-Type-Options' => 'nosniff'],
            $disposition
        );
    }
}
