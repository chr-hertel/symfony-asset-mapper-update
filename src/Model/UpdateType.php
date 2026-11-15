<?php

namespace AssetMapperUpdate\Model;

enum UpdateType: string
{
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';
}
