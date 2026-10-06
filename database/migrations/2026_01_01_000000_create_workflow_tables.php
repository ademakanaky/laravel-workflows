<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('managed_by', 32)->default('code')->index();
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('checksum', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'version']);
            $table->unique(['workflow_definition_id', 'checksum']);
        });

        Schema::create('workflow_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_final')->default(false);
            $table->string('assignment_strategy')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['workflow_version_id', 'key']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
            $table->foreignId('from_state_id')->constrained('workflow_states')->cascadeOnDelete();
            $table->foreignId('to_state_id')->constrained('workflow_states')->cascadeOnDelete();
            $table->string('action');
            $table->string('name');
            $table->json('guards')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['workflow_version_id', 'from_state_id', 'action'], 'workflow_transition_lookup_unique');
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions');
            $table->foreignId('workflow_version_id')->constrained('workflow_versions');
            $table->foreignId('current_state_id')->constrained('workflow_states');
            $table->string('subject_type', 191);
            $table->string('subject_id', 191);
            $table->string('started_by_type', 191)->nullable();
            $table->string('started_by_id', 191)->nullable();
            $table->string('status', 32)->index();
            $table->json('context')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['workflow_definition_id', 'subject_type', 'subject_id'], 'workflow_subject_lookup');
            $table->index(['started_by_type', 'started_by_id'], 'workflow_started_by_lookup');
            $table->unique(['workflow_definition_id', 'subject_type', 'subject_id', 'idempotency_key'], 'workflow_start_idempotency_unique');
        });

        Schema::create('workflow_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('workflow_state_id')->constrained('workflow_states');
            $table->string('assignee_type', 191)->nullable();
            $table->string('assignee_id', 191)->nullable();
            $table->string('status', 32)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assignee_type', 'assignee_id'], 'workflow_assignee_lookup');
        });

        Schema::create('workflow_transition_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('from_state_id')->nullable()->constrained('workflow_states');
            $table->foreignId('to_state_id')->constrained('workflow_states');
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->string('action');
            $table->json('data')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['actor_type', 'actor_id'], 'workflow_actor_lookup');
            $table->unique(['workflow_instance_id', 'idempotency_key'], 'workflow_transition_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_logs');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_states');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
    }
};
