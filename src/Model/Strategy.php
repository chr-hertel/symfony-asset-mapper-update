<?php

namespace AssetMapperUpdate\Model;

enum Strategy: string
{
    case Individual = 'individual';
    case Grouped = 'grouped';
    case Skip = 'skip';
}
