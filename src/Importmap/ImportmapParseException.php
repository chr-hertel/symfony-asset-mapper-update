<?php

namespace AssetMapperUpdate\Importmap;

final class ImportmapParseException extends \RuntimeException
{
    public function __construct(string $message, string $text, int $offset)
    {
        parent::__construct(\sprintf('importmap.php: %s (line %d)', $message, substr_count($text, "\n", 0, min($offset, \strlen($text))) + 1));
    }
}
