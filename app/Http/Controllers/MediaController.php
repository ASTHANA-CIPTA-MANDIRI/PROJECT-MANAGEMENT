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

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name
        );
    }
}
