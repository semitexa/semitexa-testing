<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

/**
 * Unique marker injected into request payloads for cross-request isolation verification.
 *
 * Markers use the "ISO_" prefix for easy identification and cleanup in test databases.
 */
final readonly class IsolationMarker
{
    public function __construct(
        public string $id,
        public string $fieldName,
    ) {}

    public static function generate(string $fieldName): self
    {
        return new self(
            id: 'ISO_' . bin2hex(random_bytes(6)),
            fieldName: $fieldName,
        );
    }
}
