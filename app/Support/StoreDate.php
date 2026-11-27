<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class StoreDate
{
    public static function date(?CarbonInterface $date): string
    {
        return $date ? self::local($date)->format('d M Y') : '-';
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date ? self::local($date)->format('d M Y, h:i A') : '-';
    }

    /**
     * Value for a datetime-local input, shown in the store's local time.
     */
    public static function inputValue(?CarbonInterface $date): string
    {
        return $date ? self::local($date)->format('Y-m-d\TH:i') : '';
    }

    /**
     * Reads a date-time typed in the store's local time and returns it in UTC for storage.
     */
    public static function fromInput(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value, self::timezone())->utc();
    }

    /**
     * UTC start of a calendar day picked in the store's local time.
     */
    public static function startOfLocalDay(string $date): Carbon
    {
        return Carbon::parse($date, self::timezone())->startOfDay()->utc();
    }

    /**
     * UTC end of a calendar day picked in the store's local time.
     */
    public static function endOfLocalDay(string $date): Carbon
    {
        return Carbon::parse($date, self::timezone())->endOfDay()->utc();
    }

    public static function export(?CarbonInterface $date): string
    {
        return $date ? self::local($date)->format('Y-m-d H:i:s') : '';
    }

    private static function local(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(self::timezone());
    }

    private static function timezone(): string
    {
        return config('app.display_timezone', 'Europe/London');
    }
}
