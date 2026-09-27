<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('function', 100);
            $table->foreignId('transferred_from_id')->nullable()->constrained('memberships')->restrictOnDelete();
            ValidityColumns::add($table, ['person_id', 'organization_id']);
            $table->index(['organization_id', 'valid_from']);
            $table->timestamps(6);
        });
        DB::unprepared("CREATE TRIGGER memberships_no_delete BEFORE DELETE ON memberships
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Membership history cannot be deleted'");
    }

    public function down(): void
    {
        if (Schema::hasTable('memberships') && DB::table('memberships')->exists()) {
            throw new RuntimeException('Retain membership history when rolling back application code.');
        }
        Schema::dropIfExists('memberships');
    }
};
