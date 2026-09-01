<?php

namespace App\Support\Media;

use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/**
 * The default generator returns a raw disk/public URL, which for a private
 * disk either 404s or (worse, if the disk is ever misconfigured back to
 * "public") skips authorization entirely. Point every media URL at the
 * authenticated media.show route instead, which checks TicketPolicy/
 * ProjectPolicy before streaming the file - see MediaController.
 */
class AuthorizedUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        return route('media.show', ['media' => $this->media->getKey()]);
    }
}
