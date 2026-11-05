<?php

namespace App\Services\Seo;

final class AnalysisText
{
    public static function normalize(?string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(SeoResolver::text($value), 'UTF-8')) ?? '');
    }

    public static function key(?string $value): ?string
    {
        $normalized = self::normalize($value);
        return $normalized === '' ? null : hash('sha256', $normalized);
    }

    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[’\x27-][\p{L}\p{N}]+)*/u', $text, $matches);
        return $matches[0];
    }

    public static function contains(string $text, string $phrase): bool
    {
        $phrase = self::normalize($phrase);
        return $phrase !== '' && str_contains(' '.self::normalize($text).' ', ' '.$phrase.' ');
    }
}
