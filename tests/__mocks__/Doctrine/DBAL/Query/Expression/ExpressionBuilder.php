<?php

/**
 * Mock Doctrine DBAL Query ExpressionBuilder for testing
 */

namespace Doctrine\DBAL\Query\Expression;

class ExpressionBuilder
{
    // Constants for comparison operators
    public const EQ = '=';
    public const NEQ = '<>';
    public const LT = '<';
    public const LTE = '<=';
    public const GT = '>';
    public const GTE = '>=';

    public function and($x, $y): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_AND, [$x, $y]);
    }

    public function or($x, $y): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_OR, [$x, $y]);
    }

    public function comparison($x, $operator, $y): string
    {
        return "$x $operator $y";
    }

    public function eq($x, $y): string
    {
        return "$x = $y";
    }

    public function neq($x, $y): string
    {
        return "$x <> $y";
    }

    public function lt($x, $y): string
    {
        return "$x < $y";
    }

    public function lte($x, $y): string
    {
        return "$x <= $y";
    }

    public function gt($x, $y): string
    {
        return "$x > $y";
    }

    public function gte($x, $y): string
    {
        return "$x >= $y";
    }

    public function isNull($x): string
    {
        return "$x IS NULL";
    }

    public function isNotNull($x): string
    {
        return "$x IS NOT NULL";
    }

    public function like($x, $y): string
    {
        return "$x LIKE $y";
    }

    public function notLike($x, $y): string
    {
        return "$x NOT LIKE $y";
    }

    public function in($x, $y): string
    {
        return "$x IN ($y)";
    }

    public function notIn($x, $y): string
    {
        return "$x NOT IN ($y)";
    }
}

class CompositeExpression
{
    public const TYPE_AND = 'AND';
    public const TYPE_OR = 'OR';

    private string $type;
    private array $parts;

    public function __construct(string $type, array $parts = [])
    {
        $this->type = $type;
        $this->parts = $parts;
    }

    public function add($part): self
    {
        $this->parts[] = $part;
        return $this;
    }

    public function __toString(): string
    {
        if (count($this->parts) === 1) {
            return (string) $this->parts[0];
        }
        return '(' . implode(') ' . $this->type . ' (', $this->parts) . ')';
    }
}
