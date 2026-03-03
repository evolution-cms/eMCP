<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Support;

final class Redactor
{
    /**
     * @var array<int, string>
     */
    private array $keys;

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(array $keys = [])
    {
        $normalized = [];
        foreach ($keys as $key) {
            $key = strtolower(trim((string)$key));
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        $this->keys = array_values(array_unique($normalized));
    }

    /**
     * @param  mixed  $payload
     * @return mixed
     */
    public function redact(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }

        return $this->redactArray($payload);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redactArray(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $keyName = strtolower(trim((string)$key));

            if ($keyName !== '' && in_array($keyName, $this->keys, true)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
