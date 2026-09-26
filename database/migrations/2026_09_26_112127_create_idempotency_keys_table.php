<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Idempotency keys (E1.8): one row per (operation, actor, client key), written with the operation's result. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('owner', 250);
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->json('response')->nullable();
            $table->dateTime('created_at', 6)->index();
            $table->unique(['scope', 'owner', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
