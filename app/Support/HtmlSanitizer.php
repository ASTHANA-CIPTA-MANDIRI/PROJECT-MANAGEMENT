<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizes user-supplied rich-text (ticket & comment content) before it is
 * stored or rendered, keeping safe formatting while stripping anything that
 * could execute in the browser (<script>, event handlers, javascript: URLs,
 * <iframe>, etc.).
 *
 * This is the single choke point behind the `content` mutators on the Ticket
 * and TicketComment models, so every write path — API, Filament form, Livewire
 * comment submit, factories, console — is covered.
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * Clean a rich-text HTML string. Null passes through untouched so nullable
     * columns keep working; everything else is purified against a whitelist.
     */
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        // Tags the rich editor can legitimately produce. Everything else
        // (script, style, iframe, form, …) is removed.
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'span', 'div',
            'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
            'ul', 'ol', 'li',
            'blockquote', 'pre', 'code',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'hr',
            'a[href|title|target|rel]',
            'img[src|alt|title|width|height]',
        ]));

        // Only these URL schemes survive on href/src — blocks javascript:,
        // data:, vbscript: payloads.
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);

        // Harden outbound links opened in a new tab.
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);

        // Don't rely on a writable on-disk definition cache; the instance is
        // reused for the whole process, so the definition is built only once.
        $config->set('Cache.DefinitionImpl', null);

        return self::$purifier = new HTMLPurifier($config);
    }
}
