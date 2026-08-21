<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name_fr');
            $table->string('name_ar');
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->jsonb('capabilities');
            $table->boolean('reconstructed')->default(false);
            $table->timestampsTz();

            $table->unique(['plan_id', 'version']);
            $table->unique(['plan_id', 'id']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->after('id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('plan_version_id')->nullable()->after('plan_id');
        });

        foreach (DB::table('plans')->orderBy('id')->get() as $plan) {
            $versionId = DB::table('plan_versions')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'plan_id' => $plan->id,
                'version' => 1,
                'name_fr' => $plan->name_fr,
                'name_ar' => $plan->name_ar,
                'description_fr' => $plan->description_fr,
                'description_ar' => $plan->description_ar,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'duration_days' => $plan->duration_days,
                'capabilities' => $plan->capabilities,
                'reconstructed' => true,
                'created_at' => $plan->created_at,
                'updated_at' => $plan->updated_at,
            ]);

            DB::table('plans')->where('id', $plan->id)->update(['current_version_id' => $versionId]);
            DB::table('orders')->where('plan_id', $plan->id)->update(['plan_version_id' => $versionId]);
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->foreign(['id', 'current_version_id'])
                ->references(['plan_id', 'id'])->on('plan_versions')->restrictOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign(['plan_id', 'plan_version_id'])
                ->references(['plan_id', 'id'])->on('plan_versions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE orders ALTER COLUMN plan_version_id SET NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION plan_versions_are_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Une version d offre est immuable';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER plan_versions_immutable
                BEFORE UPDATE OR DELETE ON plan_versions
                FOR EACH ROW EXECUTE FUNCTION plan_versions_are_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS plan_versions_immutable ON plan_versions');
        DB::statement('DROP FUNCTION IF EXISTS plan_versions_are_immutable()');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['plan_id', 'plan_version_id']);
            $table->dropColumn('plan_version_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['id', 'current_version_id']);
            $table->dropColumn('current_version_id');
        });

        Schema::dropIfExists('plan_versions');
    }
};
