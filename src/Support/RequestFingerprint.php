<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Illuminate\Database\Eloquent\Model;

final class RequestFingerprint
{
    /** @param array<string, mixed> $payload */
    public static function make(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    /** @return array{type: string, id: string}|null */
    public static function model(?Model $model): ?array
    {
        return $model ? [
            'type' => $model->getMorphClass(),
            'id' => (string) $model->getKey(),
        ] : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
