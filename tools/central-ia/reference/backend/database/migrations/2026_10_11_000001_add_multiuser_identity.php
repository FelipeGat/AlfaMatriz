<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_identities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('issuer');
            $t->string('subject');
            $t->string('status')->default('active');
            $t->unsignedBigInteger('session_epoch')->default(1);
            $t->timestamps();
            $t->unique(['issuer', 'subject']);
        });
        foreach (['messages', 'agent_runs', 'message_deliveries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            });
        }
        Schema::create('agent_integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('provider');
            $t->string('status')->default('disconnected');
            $t->unsignedBigInteger('generation')->default(1);
            // Opaque infrastructure reference only; never store credentials here.
            $t->uuid('credential_ref')->nullable()->unique();
            $t->timestamps();
            $t->unique(['owner_id', 'provider']);
        });
        Schema::create('agent_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('integration_id')->constrained('agent_integrations')->restrictOnDelete();
            $t->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::table('agent_runs', function (Blueprint $t) {
            $t->foreignId('integration_id')->nullable()->constrained('agent_integrations')->restrictOnDelete();
            $t->unsignedBigInteger('session_epoch')->nullable();
            $t->unsignedBigInteger('integration_generation')->nullable();
        });
        Schema::table('pairing_codes', fn (Blueprint $t) => $t->unsignedBigInteger('session_epoch')->nullable());
        Schema::table('channel_bindings', fn (Blueprint $t) => $t->timestamp('revoked_at')->nullable());
        Schema::create('pending_channel_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('telegram_user_id')->unique();
            $t->string('chat_id')->unique();
            $t->timestamp('expires_at');
            $t->unsignedBigInteger('session_epoch');
            $t->timestamps();
        });
        Schema::create('agent_user_daily_usage', function (Blueprint $t) {
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->date('day');
            $t->unsignedInteger('requests')->default(0);
            $t->primary(['owner_id', 'day']);
        });
    }

    public function down(): void
    {
        // Reverting code must never remove privacy boundaries or historical data.
        throw new RuntimeException('Multiuser rollback requires compatible code; destructive schema rollback refused.');
    }
};
