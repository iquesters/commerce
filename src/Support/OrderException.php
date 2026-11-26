<?php

namespace Iquesters\Commerce\Support;

/**
 * An order can't be made or moved: a product isn't on sale, sold out, an
 * amount outside its limits… $errors maps a field (e.g. "items.2") to messages,
 * so a form can show them where they belong.
 */
class OrderException extends \DomainException
{
    /** @param array<string, string[]> $errors */
    public function __construct(string $message, public array $errors = [])
    {
        parent::__construct($message);
    }

    public static function for(string $field, string $message): self
    {
        return new self($message, [$field => [$message]]);
    }
}
