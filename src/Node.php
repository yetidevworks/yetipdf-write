<?php

declare(strict_types=1);

namespace YetiPdf\Write;

/**
 * One element of the document tree. Children are Nodes or text strings.
 * The markup parser builds these; the PHP builder API can build them directly.
 */
final class Node
{
    /**
     * @param array<string,string> $attrs
     * @param list<Node|string> $children
     */
    public function __construct(
        public string $type,
        public array $attrs = [],
        public array $children = [],
        public int $line = 0,
    ) {
    }

    public function attr(string $name, ?string $default = null): ?string
    {
        return $this->attrs[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->attrs[$name]) && $this->attrs[$name] !== '';
    }

    public function bool(string $name, bool $default = false): bool
    {
        if (!isset($this->attrs[$name]) || $this->attrs[$name] === '') {
            return $default;
        }
        return !in_array(strtolower($this->attrs[$name]), ['false', '0', 'no', 'off'], true);
    }

    /** First child element of the given type. */
    public function child(string $type): ?Node
    {
        foreach ($this->children as $c) {
            if ($c instanceof Node && $c->type === $type) {
                return $c;
            }
        }
        return null;
    }
}
