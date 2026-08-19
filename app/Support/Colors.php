<?php

namespace App\Support;

/**
 * The single format every `color` column (project statuses, ticket statuses,
 * types, priorities, labels) is supposed to hold: a 6-digit hex string.
 *
 * Filament 2's ColorPicker does not validate the format server-side - it is a
 * free-text string once it reaches the request - and every one of those
 * columns is later interpolated straight into a `style="background-color: ..."`
 * / `border-color: ...` attribute. Blade escapes quotes, so the attribute
 * itself can't be broken out of, but nothing stops a value like
 * "#fff; position:fixed; inset:0; background:url(https://evil/log)" from
 * injecting extra CSS declarations.
 *
 * Two independent guards: HEX_PATTERN is the validation rule on every
 * ColorPicker form field, and safe() is the last-resort filter every view
 * applies when rendering a stored color, so a value that predates the
 * validation (or reached the column any other way) still can't inject CSS.
 */
class Colors
{
    public const HEX_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    public const DEFAULT = '#cecece';

    /**
     * A color safe to interpolate into a style attribute: the value itself if
     * it is a well-formed 6-digit hex string, DEFAULT otherwise.
     */
    public static function safe(?string $color): string
    {
        return $color !== null && preg_match(self::HEX_PATTERN, $color) === 1
            ? $color
            : self::DEFAULT;
    }
}
