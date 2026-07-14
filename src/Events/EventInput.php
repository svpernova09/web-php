<?php

declare(strict_types=1);

namespace phpweb\Events;

final class EventInput
{
    /**
     * The numeric event fields (start/end date parts and recurrence) that must be
     * integers before being passed to checkdate() or mktime().
     */
    private const NUMERIC_FIELDS = [
        'sday', 'smonth', 'syear', 'eday',
        'emonth', 'eyear', 'recur', 'recur_day',
    ];

    /**
     * Coerce the numeric event fields of a raw request array to integers.
     *
     * Untrusted input (non-numeric strings, arrays, missing values) would otherwise
     * reach checkdate()/mktime() and throw a TypeError under PHP 8. Anything that is
     * not a numeric value becomes 0, i.e. an invalid date that the normal form
     * validation already rejects. Non-numeric fields are left untouched.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function normalizeNumericFields(array $post): array
    {
        foreach (self::NUMERIC_FIELDS as $field) {
            $value = $post[$field] ?? null;
            $post[$field] = is_numeric($value) ? (int) $value : 0;
        }

        return $post;
    }
}
