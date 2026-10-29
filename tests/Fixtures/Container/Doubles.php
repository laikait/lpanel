<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Container;

interface Greeter
{
    public function greet(): string;
}

final class EnglishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'hello';
    }
}

final class Leaf {}

final class Middle
{
    public function __construct(public readonly Leaf $leaf) {}
}

final class Root
{
    public function __construct(public readonly Middle $middle) {}
}

final class NeedsGreeter
{
    public function __construct(public readonly Greeter $greeter) {}
}

final class NeedsScalar
{
    public function __construct(public readonly string $dsn) {}
}

final class ScalarWithDefault
{
    public function __construct(public readonly string $dsn = 'sqlite::memory:') {}
}

final class NullableDependency
{
    public function __construct(public readonly ?Leaf $leaf) {}
}

final class VariadicLeaves
{
    /** @var list<Leaf> */
    public readonly array $leaves;

    public function __construct(Leaf ...$leaves)
    {
        $this->leaves = \array_values($leaves);
    }
}

final class UnionDependency
{
    public function __construct(public readonly Leaf|Middle $either) {}
}

final class IntersectionDependency
{
    /** @param \Countable&\ArrayAccess<array-key, mixed> $both */
    public function __construct(public readonly \Countable&\ArrayAccess $both) {}
}

final class Untyped
{
    public mixed $whatever;

    /** @param mixed $whatever */
    public function __construct($whatever)
    {
        $this->whatever = $whatever;
    }
}

final class MixedDependency
{
    public function __construct(public readonly mixed $anything) {}
}

final class PrivateConstructor
{
    private function __construct() {}
}

abstract class AbstractThing {}

final class CircularA
{
    public function __construct(public readonly CircularB $b) {}
}

final class CircularB
{
    public function __construct(public readonly CircularC $c) {}
}

final class CircularC
{
    public function __construct(public readonly CircularA $a) {}
}

final class Invokable
{
    public function __construct(private readonly Leaf $leaf) {}

    public function leaf(): Leaf
    {
        return $this->leaf;
    }

    public function __invoke(string $suffix = '!'): string
    {
        return 'invoked' . $suffix;
    }
}

final class Methods
{
    public function __construct(public readonly Leaf $leaf) {}

    public function instanceMethod(Leaf $leaf, string $label = 'none'): string
    {
        return $label . ':' . ($leaf === $this->leaf ? 'same' : 'other');
    }

    public static function staticMethod(Greeter $greeter, string $label = 'none'): string
    {
        return $label . ':' . $greeter->greet();
    }
}
