<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('audit_entries')->whereNotNull('before_values')->orWhereNotNull('after_values')->exists()) {
            throw new RuntimeException('Cannot remove recorded audit changes. Retain the columns when rolling back code.');
        }
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropColumn(['before_values', 'after_values']);
        });
    }
};
