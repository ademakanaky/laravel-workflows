<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Concerns\ParticipatesInWorkflows;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UuidActor extends Model
{
    use HasUuids, ParticipatesInWorkflows;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
