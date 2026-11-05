<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicHttpsUrl implements ValidationRule
{
    public function __construct(private bool $originOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL) || preg_match('/[\s\\\\<>"\x00-\x1f\x7f]/u', $value)) {
            $fail('Use a valid public HTTPS URL.');
            return;
        }
        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        $ip = trim($host, '[]');
        $invalid = ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !str_contains($host, '.') || str_ends_with($host, '.')
            || preg_match('/\.(localhost|local|internal|test|invalid)$/i', $host)
            || filter_var($ip, FILTER_VALIDATE_IP)
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
        if ($this->originOnly) {
            $invalid = $invalid || isset($parts['query']) || !in_array($parts['path'] ?? '', ['', '/'], true);
        }
        if ($invalid) {
            $fail($this->originOnly ? 'Enter only a public HTTPS origin, such as https://publication.example.com (no path, query, credentials, or custom port).' : 'Use a public HTTPS URL without credentials, fragments, or a custom port.');
        }
    }
}
