<?php

use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$applicationPath = getcwd();
if ($applicationPath === false) {
    throw new RuntimeException('Unable to determine the clean application path.');
}

require $applicationPath.'/vendor/autoload.php';

$app = require $applicationPath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$workflows = $app->make(WorkflowManager::class);
$workflows->define(
    WorkflowBlueprint::make('account-review')
        ->state('pending', initial: true)
        ->state('approved', final: true)
        ->transition('approve', 'pending', 'approved')
);

$user = User::create([
    'name' => 'Workflow Smoke Test',
    'email' => 'workflow-smoke-'.str()->uuid().'@example.test',
    'password' => 'not-used',
]);
$instance = $workflows->start($user, 'account-review', context: ['source' => 'smoke-test']);
$instance = $workflows->transition($instance, 'approve');

if ($instance->currentState->key !== 'approved' || $instance->logs()->count() !== 2) {
    throw new RuntimeException('The clean-application workflow smoke test failed.');
}

echo "Clean application workflow smoke test passed.\n";
