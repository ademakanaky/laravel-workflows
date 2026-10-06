<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Concerns\HasWorkflows;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UuidDocument extends Model
{
    use HasUuids, HasWorkflows;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
