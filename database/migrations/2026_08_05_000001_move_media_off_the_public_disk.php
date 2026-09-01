<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Ticket attachments and project covers used to be stored on the "public"
 * disk (symlinked to public/storage, served by the web server with no
 * authorization check at all). Any files already uploaded before the fix
 * need to physically move to the private "media" disk so the symlink can't
 * be used to bypass TicketPolicy/ProjectPolicy - see MediaController.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->moveDisk('public', 'media');
    }

    public function down(): void
    {
        $this->moveDisk('media', 'public');
    }

    private function moveDisk(string $from, string $to): void
    {
        Media::where('disk', $from)->orWhere('conversions_disk', $from)
            ->get()
            ->each(function (Media $media) use ($from, $to) {
                $path = $media->getPathRelativeToRoot();

                if ($media->disk === $from && Storage::disk($from)->exists($path)) {
                    Storage::disk($to)->put($path, Storage::disk($from)->get($path));
                    Storage::disk($from)->delete($path);
                    $media->disk = $to;
                }

                if ($media->conversions_disk === $from) {
                    $media->conversions_disk = $to;
                }

                $media->save();
            });
    }
};
