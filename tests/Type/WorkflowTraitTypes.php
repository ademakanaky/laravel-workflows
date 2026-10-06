<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Type;

use Ademakanaky\LaravelWorkflows\Concerns\HasWorkflows;
use Ademakanaky\LaravelWorkflows\Concerns\ParticipatesInWorkflows;
use Illuminate\Database\Eloquent\Model;

abstract class WorkflowSubjectModel extends Model
{
    use HasWorkflows;
}

abstract class WorkflowActorModel extends Model
{
    use ParticipatesInWorkflows;
}
