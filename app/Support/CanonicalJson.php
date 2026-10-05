<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

final class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>  $value
     *
     * @throws JsonException
     */
    public function encode(array $value): string
    {
        return json_encode(
            $this->sort($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sort($item);
            }
        }

        return $value;
    }
}
