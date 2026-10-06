<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('active_version_id')->nullable()->index();
        });

        Schema::create('workflow_state_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_state_id')->constrained('workflow_states')->cascadeOnDelete();
            $table->string('candidate_type', 191);
            $table->string('candidate_id', 191);
            $table->timestamps();
            $table->unique(['workflow_state_id', 'candidate_type', 'candidate_id'], 'workflow_state_candidate_unique');
            $table->index(['candidate_type', 'candidate_id'], 'workflow_state_candidate_lookup');
        });

        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->timestamp('assigned_at')->nullable()->after('due_at');
            $table->timestamp('claimed_at')->nullable()->after('assigned_at');
            $table->timestamp('last_nudged_at')->nullable()->after('claimed_at');
            $table->unsignedInteger('nudge_count')->default(0)->after('last_nudged_at');
        });

        Schema::create('workflow_task_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_task_id')->constrained('workflow_tasks')->cascadeOnDelete();
            $table->string('candidate_type', 191);
            $table->string('candidate_id', 191);
            $table->timestamps();
            $table->unique(['workflow_task_id', 'candidate_type', 'candidate_id'], 'workflow_task_candidate_unique');
            $table->index(['candidate_type', 'candidate_id'], 'workflow_task_candidate_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_task_candidates');

        Schema::table('workflow_tasks', function (Blueprint $table): void {
            $table->dropColumn(['assigned_at', 'claimed_at', 'last_nudged_at', 'nudge_count']);
        });

        Schema::dropIfExists('workflow_state_candidates');

        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'active_version_id']);
        });
    }
};
