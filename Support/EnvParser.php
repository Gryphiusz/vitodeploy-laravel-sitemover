<?php

namespace App\Vito\Plugins\Gryphiusz\VitodeployLaravelSitemover\Support;

class EnvParser
{
    /**
     * @return array<string, string>
     */
    public function parse(string $env): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $env) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $rawValue] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $values[$key] = $this->normalizeValue(trim($rawValue));
        }

        return $values;
    }

    public function update(string $env, string $key, string $value): string
    {
        $quotedValue = $this->quote($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $env) === 1) {
            return (string) preg_replace($pattern, $key.'='.$quotedValue, $env);
        }

        $env = rtrim($env);

        return ($env === '' ? '' : $env."\n").$key.'='.$quotedValue."\n";
    }

    /**
     * @return array<int, string>
     */
    public function normalizeStoragePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }

            $path = ltrim($path, '/');
            $path = rtrim($path, '/');
            if ($path === '') {
                continue;
            }

            $normalized[] = $path;
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            return ['storage/app/public'];
        }

        return $normalized;
    }

    private function normalizeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        return str_replace(['\\n', '\\r'], ["\n", "\r"], $value);
    }

    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'|\$/', $value) === 1) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"'.$escaped.'"';
        }

        return $value;
    }
}
