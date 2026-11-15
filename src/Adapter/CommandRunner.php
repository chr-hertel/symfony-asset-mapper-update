<?php

namespace AssetMapperUpdate\Adapter;

interface CommandRunner
{
    /**
     * @param list<string>|string   $command a list of arguments, or a shell command line
     * @param array<string, string> $env     variables to add to the inherited environment
     */
    public function run(array|string $command, string $cwd, array $env = []): CommandResult;
}
