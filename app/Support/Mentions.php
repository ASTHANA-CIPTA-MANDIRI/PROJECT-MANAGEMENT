<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Parses and renders "@name" mentions inside comment text.
 *
 * Two mention forms are recognized:
 *
 *  - "@Jane Roe#42" — inserted by the autocomplete widget, where 42 is the
 *    user id. Resolved by id, so two members sharing the exact same name
 *    can never be confused with each other. The id is never shown to users.
 *  - "@Jane Roe" — typed by hand without the autocomplete. Resolved by
 *    matching the exact display name — longest name first, so a short name
 *    isn't detected inside a longer one ("John" within "John Doe"). If two
 *    candidates share the exact same name, only one is matched (whichever
 *    sorts first); this ambiguity is inherent to plain-text typing and is
 *    why the autocomplete form exists.
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

        // 1) Id-tagged mentions — unambiguous, takes priority.
        if (preg_match_all(self::idPattern(), $text, $matches)) {
            foreach ($matches[2] as $id) {
                $user = $candidates->firstWhere('id', (int) $id);
                if ($user && ! $mentioned->contains('id', $user->id)) {
                    $mentioned->push($user);
                }
            }
            $text = preg_replace(self::idPattern(), ' ', $text);
        }

        // 2) Plain "@Name" typed by hand, for whichever candidates weren't
        // already resolved by id above.
        foreach (self::longestFirst($candidates) as $user) {
            if ($mentioned->contains('id', $user->id)) {
                continue;
            }

            $pattern = self::namePattern($user->name);
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
     * Wrap every recognized mention in a highlight span, showing only the
     * name — the id suffix of an id-tagged mention is never displayed.
     * Operates on already sanitized HTML; names are re-escaped on the way
     * back in, so display stays safe.
     *
     * @param  Collection<int, \App\Models\User>  $candidates
     */
    public static function highlight(string $html, Collection $candidates): string
    {
        $replacements = [];
        $index = 0;

        $html = preg_replace_callback(self::idPattern(), function ($matches) use (&$replacements, &$index, $candidates) {
            $user = $candidates->firstWhere('id', (int) $matches[2]);
            // Fall back to the typed name if the id no longer resolves
            // (e.g. the user left the project since the comment was posted).
            $name = $user->name ?? trim($matches[1]);

            $token = "\x00MENTION{$index}\x00";
            $index++;
            $replacements[$token] = $user
                ? '<span class="text-primary-600 font-semibold">@'.e($name).'</span>'
                : '@'.e($name);

            return $token;
        }, $html);

        foreach (self::longestFirst($candidates) as $user) {
            $pattern = self::namePattern($user->name);
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
     * Strip the hidden "#id" suffix from id-tagged mentions, leaving just
     * "@Name". Used before content is loaded back into the comment editor
     * for editing, so the id marker never becomes visible/editable text.
     */
    public static function stripIds(string $content): string
    {
        return preg_replace(self::idPattern(), '@$1', $content) ?? $content;
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
    private static function namePattern(?string $name): ?string
    {
        $name = (string) $name;
        if ($name === '') {
            return null;
        }

        return '/@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';
    }

    /**
     * Regex matching "@Name#id" as inserted by the autocomplete widget.
     * Capture group 1 is the name, group 2 is the numeric id.
     */
    private static function idPattern(): string
    {
        return '/@([^\n#<]+?)#(\d+)(?!\d)/u';
    }
}
