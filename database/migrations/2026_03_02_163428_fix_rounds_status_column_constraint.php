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
        Schema::table('rounds', function (Blueprint $table) {
            // Change enum to string to avoid CHECK constraint issues in SQLite
            // and actually allow 'friction' as a value
            $table->string('status')->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            // No easy way to go back to enum in SQLite without potential data loss, 
            // but we can at least make it a string again if needed.
            $table->string('status')->change();
        });
    }
};
