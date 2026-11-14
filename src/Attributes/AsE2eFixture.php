<?php

declare(strict_types=1);

namespace Semitexa\Testing\Attributes;

use Attribute;

/**
 * Marks a class that puts what E2E specs need into the E2E database, and only
 * there: `e2e:fixtures` runs it, and only after E2eDatabaseGuard has found the
 * database the E2E runner made (tk-e2e-specs-own-their-data). Never a data
 * patch: `bin/semitexa update` runs patches in production too.
 *
 * Fixtures run in ascending priority, then by id, so one that needs another's
 * rows (a tenant before its users) declares a higher number.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsE2eFixture
{
    public function __construct(
        public string $id,
        public int $priority = 0,
    ) {
    }
}
