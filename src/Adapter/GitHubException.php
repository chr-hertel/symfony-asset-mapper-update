<?php

namespace AssetMapperUpdate\Adapter;

final class GitHubException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
