<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_daily_usage', function (Blueprint $t) {
            $t->date('day')->primary();
            $t->unsignedInteger('requests')->default(0);
        });
        Schema::create('agent_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $t->foreignId('response_message_id')->nullable()->unique()->constrained('messages')->restrictOnDelete();
            $t->string('provider')->default('codex');
            $t->string('mode')->default('mock');
            $t->string('status')->default('queued')->index();
            $t->string('error_code')->nullable();
            $t->string('provider_thread_id')->nullable();
            $t->json('usage')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_daily_usage');
    }
};
