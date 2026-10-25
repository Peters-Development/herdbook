<?php

namespace App\Services\Checks;

use Illuminate\Support\Str;

/**
 * What one check run found. The error is in the active language: it is
 * shown in the panel.
 */
final readonly class CheckOutcome
{
    public ?string $error;

    public function __construct(
        public bool $ok,
        public ?int $responseMs = null,
        public ?int $statusCode = null,
        ?string $error = null,
    ) {
        // Cut to the column: MySQL refuses longer values (docs/development.md#long-strings).
        $this->error = $error === null ? null : Str::limit($error, 495);
    }

    public static function up(?int $responseMs = null, ?int $statusCode = null): self
    {
        return new self(true, $responseMs, $statusCode);
    }

    public static function down(string $error, ?int $responseMs = null, ?int $statusCode = null): self
    {
        return new self(false, $responseMs, $statusCode, $error);
    }
}
