<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a registration means, so a plan can be filed by naming one.
 *
 * This is the single biggest saving in the whole feature. Items 9, 10 and most
 * of 19 -- type, wake turbulence category, equipment, cruising speed,
 * endurance, colours, survival gear -- are facts about an airframe, identical
 * on every flight it ever makes. Once "LV-HAW" is on file, a flight plan is
 * four things: where from, where to, when, and how many aboard.
 *
 * Per phone rather than global. The type and the wake category of a
 * registration are public facts, but the endurance a pilot actually plans to,
 * the operator they file under and how they word the aircraft's colours are
 * not, and one global row would hand one pilot's answers to another. Seeding
 * the objective columns from a public register later would not change that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircraft_profiles', function (Blueprint $table) {
            $table->id();

            $table->string('phone')->index();

            // Stored the way it is written, "LV-HAW". The lookup normalises,
            // so the form gets what the pilot would have typed.
            $table->string('registration', 10);

            // Item 9: ICAO type designator and wake turbulence category.
            $table->string('aircraft_type', 4)->nullable();
            $table->string('wake_turbulence', 1)->nullable();

            // Item 10, either side of the printed slash: what it carries, and
            // what it answers a radar with.
            $table->string('equipment_com', 20)->nullable();
            $table->string('equipment_surveillance', 20)->nullable();

            // Item 15's speed as it is written ("N0175"), and the same number
            // as knots, which is what an EET is worked out from. Both, because
            // the form wants the string and the arithmetic wants the integer,
            // and deriving one from the other at every use is how they drift.
            $table->string('cruising_speed', 5)->nullable();
            $table->unsignedSmallInteger('cruise_tas_kt')->nullable();
            $table->string('default_level', 5)->nullable();

            // Item 19.
            $table->string('endurance', 4)->nullable();
            $table->string('colour_markings')->nullable();
            $table->json('emergency_radio')->nullable();
            $table->json('survival_equipment')->nullable();
            $table->json('jackets')->nullable();
            $table->json('dinghies')->nullable();

            // Which profile to reach for when a message names no registration
            // at all: the one this pilot files with most.
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['phone', 'registration']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircraft_profiles');
    }
};
