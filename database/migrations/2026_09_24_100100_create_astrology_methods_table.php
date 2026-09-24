<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('astrology_methods', function (Blueprint $table) {
            $table->id();
            // Null for the built-in methods every workspace sees; set for a workspace's own methods.
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->string('suggested_house_system', 32)->nullable();
            $table->string('suggested_zodiac_mode', 16)->nullable();
            $table->string('suggested_ayanamsa', 32)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('workspace_astrology_method', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('astrology_method_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);

            $table->primary(['workspace_id', 'astrology_method_id']);
        });

        // Built-in methods are reference data, so they ship with the schema rather than a
        // seeder that could be forgotten on deploy. Names are translated on the frontend
        // by slug; the English name here is the fallback. Suggested chart parameters
        // follow docs/spec/01; methods without a stated default carry none.
        $now = now();

        DB::table('astrology_methods')->insert(array_map(fn (array $method) => $method + [
            'workspace_id' => null,
            'suggested_house_system' => null,
            'suggested_zodiac_mode' => null,
            'suggested_ayanamsa' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['slug' => 'western', 'name' => 'Western Astrology', 'suggested_zodiac_mode' => 'tropical', 'suggested_house_system' => 'placidus'],
            ['slug' => 'vedic', 'name' => 'Vedic Astrology / Jyotish', 'suggested_zodiac_mode' => 'sidereal', 'suggested_ayanamsa' => 'lahiri', 'suggested_house_system' => 'whole_sign'],
            ['slug' => 'chinese', 'name' => 'Chinese Astrology'],
            ['slug' => 'hellenistic', 'name' => 'Hellenistic Astrology', 'suggested_zodiac_mode' => 'tropical', 'suggested_house_system' => 'whole_sign'],
            ['slug' => 'psychological', 'name' => 'Psychological Astrology'],
            ['slug' => 'evolutionary', 'name' => 'Evolutionary Astrology'],
            ['slug' => 'horary', 'name' => 'Horary Astrology'],
            ['slug' => 'electional', 'name' => 'Electional Astrology'],
            ['slug' => 'other', 'name' => 'Other'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_astrology_method');
        Schema::dropIfExists('astrology_methods');
    }
};
