<?php

namespace App\Services\IncidentImport;

/**
 * What the importer reads from one incident report (markdown).
 */
final readonly class ParsedIncident
{
    public function __construct(
        public string $title,
        public string $occurredOn,
        public ?string $impact,
        public string $body,
    ) {}
}
