<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Concerns\HasWorkflows;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasWorkflows;

    protected $guarded = [];
}
