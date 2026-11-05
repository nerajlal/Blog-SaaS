<?php
namespace App\Services\Links;

/** Compare JSON snapshots without relying on database object-key ordering or loose value coercion. */
final class LinkState
{
    public static function same(array $left, array $right): bool
    {
        return self::sorted($left) === self::sorted($right);
    }
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) if (is_array($item)) $item = self::sorted($item);
        return $value;
    }
}
