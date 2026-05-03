<?php

declare(strict_types=1);

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;

$pascalCasePhp = '/^[A-Z][A-Za-z0-9]*\.php$/';

return new LocalModuleStructureExtension(
    package: 'testing',
    topLevelDirectories: [
        'Attributes',
        'Contract',
        'Data',
        'Factory',
        'Strategy',
        'Traits',
        'Transport',
    ],
    topLevelFiles: [
        'FailureReporter.php',
        'PayloadContractTester.php',
        'PhpUnitExtension.php',
    ],
    pathRules: [
        'Attributes' => new ModuleStructureRule(
            path: 'Attributes',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public PHP attributes consumed directly by application payloads.',
        ),
        'Contract' => new ModuleStructureRule(
            path: 'Contract',
            allowedFilePatterns: ['/^[A-Z][A-Za-z0-9]*Interface\.php$/'],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public strategy and transport contracts.',
        ),
        'Data' => new ModuleStructureRule(
            path: 'Data',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public DTOs and result enums used by strategies and transports.',
        ),
        'Factory' => new ModuleStructureRule(
            path: 'Factory',
            allowedFilePatterns: ['/^[A-Z][A-Za-z0-9]*Factory\.php$/'],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public metadata factory API.',
        ),
        'Strategy' => new ModuleStructureRule(
            path: 'Strategy',
            allowedDirectories: ['Profile'],
            allowedFilePatterns: [$pascalCasePhp],
            rationale: 'semitexa-testing public executable strategies referenced from payload attributes.',
        ),
        'Strategy/Profile' => new ModuleStructureRule(
            path: 'Strategy/Profile',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public strategy profiles.',
        ),
        'Traits' => new ModuleStructureRule(
            path: 'Traits',
            allowedFiles: ['TestsPayloads.php'],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public PHPUnit integration trait.',
        ),
        'Transport' => new ModuleStructureRule(
            path: 'Transport',
            allowedFilePatterns: ['/^[A-Z][A-Za-z0-9]*Transport\.php$/'],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-testing public transport implementations.',
        ),
    ],
    reason: 'semitexa-testing exposes a testing DSL and PHPUnit-facing API that applications import directly; these public primitives are intentionally outside the generic Application/Domain split.',
);
