<?php

namespace AssetMapperUpdate\Model;

/**
 * Pull requests are grouped by bucket. The cases are in plan order.
 */
enum Bucket: string
{
    case Security = 'security';
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    public static function fromUpdateType(UpdateType $type): self
    {
        return self::from($type->value);
    }
}
