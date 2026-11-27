<?php

namespace App\Support;

/**
 * Rows-per-page choice shared by every paginated list (?per_page=25).
 * Only the listed sizes are accepted, anything else falls back to the list's own default.
 */
class PerPage
{
    public const OPTIONS = [10, 25, 50, 100];

    public static function size(int $default = 10): int
    {
        $requested = (int) request()->query('per_page', 0);

        return in_array($requested, self::OPTIONS, true) ? $requested : $default;
    }

    /**
     * Options to show in the selector, always including the page's current size.
     *
     * @return list<int>
     */
    public static function optionsFor(int $current): array
    {
        $options = array_values(array_unique([...self::OPTIONS, $current]));
        sort($options);

        return $options;
    }
}
