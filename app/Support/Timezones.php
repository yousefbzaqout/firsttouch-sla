<?php

declare(strict_types=1);

namespace App\Support;

final class Timezones
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        /** @var array<string, string> $options */
        $options = [];

        foreach (timezone_identifiers_list() as $identifier) {
            $options[$identifier] = $identifier;
        }

        return $options;
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }

    public static function resolve(?string $timezone, string $default = 'UTC'): string
    {
        if (is_string($timezone) && $timezone !== '' && self::isValid($timezone)) {
            return $timezone;
        }

        return self::isValid($default) ? $default : 'UTC';
    }
}
