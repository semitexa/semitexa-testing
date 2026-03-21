<?php

declare(strict_types=1);

namespace Semitexa\Testing\Factory;

use ReflectionClass;
use ReflectionNamedType;
use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Testing\Attributes\TestablePayload;
use Semitexa\Testing\Attributes\TestablePayloadPart;
use Semitexa\Testing\Data\PayloadMetadata;
use Semitexa\Testing\Data\PropertyMeta;

final class PayloadMetadataFactory
{
    private const PUBLIC_ENDPOINT_ATTRIBUTE = 'Semitexa\\Authorization\\Attributes\\PublicEndpoint';

    /** @var array<class-string, PayloadMetadata> */
    private static array $cache = [];

    /**
     * @param class-string $payloadClass
     */
    public static function create(string $payloadClass): PayloadMetadata
    {
        if (isset(self::$cache[$payloadClass])) {
            return self::$cache[$payloadClass];
        }

        $ref = new ReflectionClass($payloadClass);

        // --- #[AsPayload] ---
        $asPayloadAttrs = $ref->getAttributes(AsPayload::class);
        $asPayload = !empty($asPayloadAttrs) ? $asPayloadAttrs[0]->newInstance() : null;
        $path = $asPayload?->path ?? '/';
        $methods = $asPayload?->methods ?? ['GET'];

        // --- #[PublicEndpoint] — walk class hierarchy (PHP attributes are not inherited) ---
        $isPublic = self::hasPublicEndpoint($ref);

        // --- #[TestablePayload] ---
        $testableAttrs = $ref->getAttributes(TestablePayload::class);
        $testable = !empty($testableAttrs) ? $testableAttrs[0]->newInstance() : new TestablePayload();
        $context = $testable->context;
        $strategies = $testable->strategies;

        // --- Merge strategies from #[TestablePayloadPart] traits ---
        $strategies = self::mergePartStrategies($ref, $strategies);

        // --- Public properties ---
        $properties = self::collectProperties($ref);

        $metadata = new PayloadMetadata(
            payloadClass: $payloadClass,
            path: $path,
            methods: $methods,
            isPublic: $isPublic,
            properties: $properties,
            context: $context,
            strategies: $strategies,
        );

        self::$cache[$payloadClass] = $metadata;
        return $metadata;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Walk the class hierarchy to find #[PublicEndpoint].
     * PHP attributes are not inherited, so parent classes must be checked explicitly.
     */
    private static function hasPublicEndpoint(ReflectionClass $ref): bool
    {
        if (!class_exists(self::PUBLIC_ENDPOINT_ATTRIBUTE)) {
            return false;
        }

        $current = $ref;
        while ($current !== false) {
            if ($current->getAttributes(self::PUBLIC_ENDPOINT_ATTRIBUTE) !== []) {
                return true;
            }
            $current = $current->getParentClass();
        }
        return false;
    }

    /**
     * Walk all traits (recursively) used by $ref and merge strategies
     * from any trait annotated with #[TestablePayloadPart].
     *
     * @param list<class-string> $existing
     * @return list<class-string>
     */
    private static function mergePartStrategies(ReflectionClass $ref, array $existing): array
    {
        $seen = array_flip($existing);
        $result = $existing;

        foreach (self::collectAllTraits($ref) as $traitName) {
            if (!class_exists($traitName) && !trait_exists($traitName)) {
                continue;
            }
            $traitRef = new ReflectionClass($traitName);
            $partAttrs = $traitRef->getAttributes(TestablePayloadPart::class);
            if (empty($partAttrs)) {
                continue;
            }
            /** @var TestablePayloadPart $part */
            $part = $partAttrs[0]->newInstance();
            foreach ($part->strategies as $strategyClass) {
                if (!isset($seen[$strategyClass])) {
                    $result[] = $strategyClass;
                    $seen[$strategyClass] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Collect all traits used by the class, including traits of traits.
     *
     * @return list<class-string>
     */
    private static function collectAllTraits(ReflectionClass $ref): array
    {
        $traits = [];
        $queue = array_values($ref->getTraits());

        while ($queue) {
            $trait = array_shift($queue);
            $name = $trait->getName();
            if (isset($traits[$name])) {
                continue;
            }
            $traits[$name] = true;
            foreach ($trait->getTraits() as $nested) {
                $queue[] = $nested;
            }
        }

        return array_keys($traits);
    }

    /**
     * Collect field metadata from two sources, deduplicated by name:
     *   1. Setter methods (set{Foo}(TypeHint $foo)) — primary source, covers protected/private DTO props.
     *   2. Public properties — for DTOs that expose fields directly.
     *
     * Setter-based names match the hydrator convention (RequestDtoHydrator::keyToSetterName):
     *   setEmail(string $email) → field name 'email', type 'string'
     *   setFirstName(string $firstName) → field name 'firstName'
     *
     * @return list<PropertyMeta>
     */
    private static function collectProperties(ReflectionClass $ref): array
    {
        $result = [];
        $seen = [];

        // 1. Setter methods: setFoo(Type $foo)
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!str_starts_with($name, 'set') || strlen($name) <= 3) {
                continue;
            }
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 1) {
                continue;
            }
            $params = $method->getParameters();
            if (count($params) !== 1) {
                continue;
            }

            $param = $params[0];
            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            // Derive field name: setEmail → 'email', setFirstName → 'firstName'
            $fieldName = lcfirst(substr($name, 3));

            if (isset($seen[$fieldName])) {
                continue;
            }
            $seen[$fieldName] = true;

            // Check if there's a backing property with a default value
            $hasDefault = false;
            $defaultValue = null;
            $propName = $fieldName;
            if ($ref->hasProperty($propName)) {
                $prop = $ref->getProperty($propName);
                $hasDefault = $prop->hasDefaultValue();
                $defaultValue = $hasDefault ? $prop->getDefaultValue() : null;
            } elseif ($param->isOptional()) {
                $hasDefault = true;
                $defaultValue = $param->getDefaultValue();
            }

            $result[] = new PropertyMeta(
                name: $fieldName,
                type: $type->getName(),
                nullable: $type->allowsNull(),
                hasDefault: $hasDefault,
                defaultValue: $defaultValue,
            );
        }

        // 2. Public properties not already discovered via setters
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || isset($seen[$prop->getName()])) {
                continue;
            }

            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $seen[$prop->getName()] = true;
            $hasDefault = $prop->hasDefaultValue();

            $result[] = new PropertyMeta(
                name: $prop->getName(),
                type: $type->getName(),
                nullable: $type->allowsNull(),
                hasDefault: $hasDefault,
                defaultValue: $hasDefault ? $prop->getDefaultValue() : null,
            );
        }

        return $result;
    }
}
