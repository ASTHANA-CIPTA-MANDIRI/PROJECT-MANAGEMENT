<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Parses and renders "@name" mentions inside comment text.
 *
 * A mention is the literal "@" followed by the exact display name of a
 * candidate user (project members, owner and responsible). Matching against
 * the full name — longest first — lets names that contain spaces work and
 * stops a short name ("John") from being detected inside a longer one
 * ("John Doe").
 */
class Mentions
{
    /**
     * Return the subset of $candidates actually mentioned in $content.
     *
     * @param  Collection<int, \App\Models\User>  $candidates
     * @return Collection<int, \App\Models\User>
     */
    public static function mentioned(?string $content, Collection $candidates): Collection
    {
        $text = strip_tags((string) $content);
        $mentioned = collect();

        foreach (self::longestFirst($candidates) as $user) {
            $pattern = self::pattern($user->name);
            if ($pattern && preg_match($pattern, $text)) {
                $mentioned->push($user);
                // Consume the match so a shorter name that is a prefix of this
                // one ("John" within "John Doe") is not counted as well.
                $text = preg_replace($pattern, ' ', $text);
            }
        }

        return $mentioned->values();
    }

    /**
     * Wrap every "@name" occurrence in a highlight span. Operates on already
     * sanitized HTML; the escaped name is re-inserted so display is safe.
     *
     * @param  Collection<int, \App\Models\User>  $candidates
     */
    public static function highlight(string $html, Collection $candidates): string
    {
        $replacements = [];
        $index = 0;

        foreach (self::longestFirst($candidates) as $user) {
            $pattern = self::pattern($user->name);
            if (! $pattern) {
                continue;
            }

            $html = preg_replace_callback($pattern, function () use (&$replacements, &$index, $user) {
                // A NUL-delimited token can never appear in sanitized HTML, so
                // later, shorter names cannot match inside a span we just built.
                $token = "\x00MENTION{$index}\x00";
                $replacements[$token] = '<span class="text-primary-600 font-semibold">@'.e($user->name).'</span>';
                $index++;

                return $token;
            }, $html);
        }

        return strtr($html, $replacements);
    }

    /**
     * Candidates ordered by name length, longest first.
     *
     * @param  Collection<int, \App\Models\User>  $candidates
     * @return Collection<int, \App\Models\User>
     */
    private static function longestFirst(Collection $candidates): Collection
    {
        return $candidates->sortByDesc(fn ($user) => mb_strlen((string) $user->name));
    }

    /**
     * Regex matching "@name" not followed by another word character, or null
     * when the name is empty.
     */
    private static function pattern(?string $name): ?string
    {
        $name = (string) $name;
        if ($name === '') {
            return null;
        }

        return '/@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';
    }
}
