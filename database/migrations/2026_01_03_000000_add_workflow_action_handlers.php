<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table): void {
            $table->json('handlers')->nullable();
            $table->json('after_commit_handlers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table): void {
            $table->dropColumn(['handlers', 'after_commit_handlers']);
        });
    }
};
