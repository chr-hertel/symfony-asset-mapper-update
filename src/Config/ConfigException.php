<?php

namespace AssetMapperUpdate\Config;

final class ConfigException extends \RuntimeException
{
    public function __construct(string $source, string $message)
    {
        parent::__construct(\sprintf('Invalid config %s: %s', $source, $message));
    }
}
