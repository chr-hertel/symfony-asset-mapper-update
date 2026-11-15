<?php

namespace AssetMapperUpdate\Adapter;

final class ConsoleException extends \RuntimeException
{
    public function __construct(string $command, CommandResult $result, ?string $detail = null)
    {
        parent::__construct(\sprintf("\"%s\" failed (exit code %d)%s\n%s", $command, $result->exitCode, null !== $detail ? ': '.$detail : '', $result->tail()));
    }
}
