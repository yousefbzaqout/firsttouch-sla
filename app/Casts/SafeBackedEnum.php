<?php

declare(strict_types=1);

namespace App\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ValueError;

/**
 * Casts a backed enum without crashing the app on corrupt/legacy DB values.
 *
 * @template TEnum of BackedEnum
 *
 * @implements CastsAttributes<TEnum|null, TEnum|string|int|null>
 */
final class SafeBackedEnum implements CastsAttributes
{
    /** @var class-string<TEnum> */
    private string $enumClass;

    private string|int|null $fallback;

    /**
     * @param  class-string<TEnum>  $enumClass
     */
    public function __construct(string $enumClass, string|int|null $fallback = null)
    {
        $this->enumClass = $enumClass;
        $this->fallback = $fallback;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return TEnum|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof $this->enumClass) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return $this->fallbackEnum($model, $key, $value);
        }

        try {
            /** @var TEnum|null $enum */
            $enum = $this->enumClass::tryFrom($value);
        } catch (ValueError) {
            $enum = null;
        }

        if ($enum !== null) {
            return $enum;
        }

        return $this->fallbackEnum($model, $key, $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof $this->enumClass) {
            return $value->value;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value type for [%s.%s]; expected %s|string|int|null.',
                $model::class,
                $key,
                $this->enumClass,
            ));
        }

        /** @var TEnum|null $enum */
        $enum = $this->enumClass::tryFrom($value);

        if ($enum === null) {
            throw new InvalidArgumentException(sprintf(
                'Invalid %s value [%s] for [%s.%s].',
                $this->enumClass,
                (string) $value,
                $model::class,
                $key,
            ));
        }

        return $enum->value;
    }

    /**
     * @return TEnum
     */
    private function fallbackEnum(Model $model, string $key, mixed $invalidValue): BackedEnum
    {
        Log::warning('Invalid backed enum value coerced to fallback.', [
            'model' => $model::class,
            'key' => $key,
            'value' => $invalidValue,
            'enum' => $this->enumClass,
            'id' => $model->getKey(),
        ]);

        if ($this->fallback !== null) {
            return $this->enumClass::from($this->fallback);
        }

        /** @var list<TEnum> $cases */
        $cases = $this->enumClass::cases();

        return $cases[0];
    }
}
