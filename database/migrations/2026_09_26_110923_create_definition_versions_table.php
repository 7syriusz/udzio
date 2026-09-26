<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Frozen versions of definitions (A5-11, A5-16). Rows are append-only, like audit entries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('definition_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('definition_type', 100);
            $table->unsignedBigInteger('definition_id');
            $table->unsignedInteger('version');
            $table->json('content');
            $table->char('content_hash', 64);
            $table->string('published_by_type', 32);
            $table->string('published_by_id', 191)->nullable();
            $table->dateTime('published_at', 6);
            $table->unique(['definition_type', 'definition_id', 'version']);
        });

        DB::unprepared("CREATE TRIGGER definition_versions_no_update BEFORE UPDATE ON definition_versions
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Definition versions are immutable'");
        DB::unprepared("CREATE TRIGGER definition_versions_no_delete BEFORE DELETE ON definition_versions
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Definition versions are immutable'");
    }

    public function down(): void
    {
        if (Schema::hasTable('definition_versions') && DB::table('definition_versions')->exists()) {
            throw new RuntimeException('Cannot drop definition versions referenced by execution history.');
        }

        Schema::dropIfExists('definition_versions');
    }
};
