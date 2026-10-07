<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /** `{subject}.{past-tense verb}`, e.g. `customer.created` */
            $table->string('event', 100);
            /** the aggregate's logical key in snake case (`lottery_type`), never a class name */
            $table->string('subject_type', 60);
            $table->string('subject_id', 64);
            /**
             * The auth identifier of who acted, null when the system did. A string with no foreign
             * key: the user table's key type differs between projects, and removing a user must
             * never rewrite what they did.
             */
            $table->string('actor_id', 64)->nullable()->index();
            $table->jsonb('data');
            $table->timestampTz('occurred_at')->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
