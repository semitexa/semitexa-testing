<?php

declare(strict_types=1);

namespace Semitexa\Testing;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'testing.payload-contracts',
    summary: 'Automated payload and contract testing: strategies and profiles that exercise a route from its declared types.',
    useWhen: 'Routes carry typed payload and resource contracts you want held to over time.',
    avoidWhen: 'The logic under test is a pure function - a plain unit test is closer to it.',
    replaces: [
        'a hand-written request per field, rewritten whenever the payload changes',
        'trusting that a typed payload cannot be violated by a real client',
    ],
)]
final class Capabilities
{
}
