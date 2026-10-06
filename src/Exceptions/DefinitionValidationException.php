<?php

namespace Ademakanaky\LaravelWorkflows\Exceptions;

class DefinitionValidationException extends WorkflowException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
