<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

final readonly class PropertyMeta
{
    public function __construct(
        public string $name,
        /** Primitive type name: 'int', 'string', 'bool', 'float', 'array', or FQCN for objects. */
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $defaultValue,
    ) {}
}
