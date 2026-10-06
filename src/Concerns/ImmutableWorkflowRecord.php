<?php

namespace Ademakanaky\LaravelWorkflows\Concerns;

use Ademakanaky\LaravelWorkflows\Exceptions\ImmutableWorkflowRecordException;

trait ImmutableWorkflowRecord
{
    protected static function bootImmutableWorkflowRecord(): void
    {
        static::updating(function (): never {
            throw new ImmutableWorkflowRecordException('Published workflow records are immutable.');
        });

        static::deleting(function (): never {
            throw new ImmutableWorkflowRecordException('Published workflow records are immutable.');
        });
    }
}
