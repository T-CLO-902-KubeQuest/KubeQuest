<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attache chaque clic à un utilisateur.
     * Nullable : les clics anonymes déjà en base restent valides (comptés en « global »).
     */
    public function up(): void
    {
        Schema::table('counters', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('count')
                  ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('counters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
