<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Concerns\ParticipatesInWorkflows;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use ParticipatesInWorkflows;

    protected $guarded = [];
}
