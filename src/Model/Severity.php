<?php

namespace AssetMapperUpdate\Model;

/**
 * The cases are ordered from the most to the least severe.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    public static function fromAudit(mixed $value): self
    {
        $value = \is_string($value) ? strtolower($value) : '';

        return 'moderate' === $value ? self::Medium : (self::tryFrom($value) ?? self::Unknown);
    }

    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
