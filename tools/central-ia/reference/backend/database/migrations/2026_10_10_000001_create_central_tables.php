<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('title')->default('Conversa central');
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('actor_type');
            $t->string('actor_name');
            $t->string('kind')->default('text');
            $t->string('origin');
            $t->text('content');
            $t->json('metadata')->nullable();
            $t->string('external_id')->nullable();
            $t->uuid('client_id')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'client_id']);
            $t->unique(['origin', 'external_id']);
            $t->index(['conversation_id', 'id']);
        });
        Schema::create('channel_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('telegram_user_id')->unique();
            $t->string('chat_id')->unique();
            $t->timestamps();
        });
        Schema::create('pairing_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('code_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('telegram_updates', function (Blueprint $t) {
            $t->bigInteger('update_id')->primary();
            $t->timestamp('created_at');
        });
        Schema::create('central_states', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        Schema::create('message_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('channel_binding_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('pending');
            $t->string('error_code')->nullable();
            $t->string('external_id')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event');
            $t->json('metadata')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'message_deliveries', 'central_states', 'telegram_updates', 'pairing_codes', 'channel_bindings', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
