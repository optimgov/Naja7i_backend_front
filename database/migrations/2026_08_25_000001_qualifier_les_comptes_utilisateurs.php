<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('uuid');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('academic_level', 150)->nullable()->after('last_name');
            $table->string('address', 500)->nullable()->after('academic_level');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'academic_level', 'address']);
        });
    }
};
