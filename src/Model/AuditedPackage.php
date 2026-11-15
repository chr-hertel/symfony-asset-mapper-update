<?php

namespace AssetMapperUpdate\Model;

final readonly class AuditedPackage
{
    /**
     * @param list<Advisory> $advisories
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $advisories,
    ) {
    }

    /**
     * @param list<Advisory> $advisories
     */
    public function withAdvisories(array $advisories): self
    {
        return new self($this->name, $this->version, $advisories);
    }
}
