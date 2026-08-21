<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every flight plan the bot has actually produced.
 *
 * Separate from the draft it came out of, for three reasons that are all about
 * outliving it: "repeat my last plan" has to survive the draft being
 * discarded, the PDF lives days rather than hours, and flying the same leg
 * back a week later should still be one tap.
 *
 * The filled-in values are a JSON snapshot rather than a column each. Nothing
 * queries inside a filed plan -- it is read back whole, to reprint it or to
 * seed the next one -- and thirty-odd columns that only ever move together
 * would be thirty-odd columns to migrate the day the form changes. The few
 * fields that ARE queried, because "my last plan out of here" is a question
 * someone asks, are lifted out beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_plans', function (Blueprint $table) {
            $table->id();

            $table->string('phone')->index();

            // FlightPlanFields, frozen at the moment it was confirmed.
            $table->json('data');

            // Lifted out of the snapshot so a route can be looked up without
            // opening every plan the pilot ever filed.
            $table->string('departure_anac', 8)->nullable()->index();
            $table->string('departure_icao', 4)->nullable();
            $table->string('destination_anac', 8)->nullable();
            $table->string('destination_icao', 4)->nullable();

            // UTC, so "repeat my last plan" can tell a flight that has already
            // happened from one that has not.
            $table->timestamp('departure_at')->nullable();

            $table->string('pdf_disk')->default('local');

            // Nullable because pruning deletes the file and keeps the row: the
            // plan is still worth repeating after its PDF has gone.
            $table->string('pdf_path')->nullable();

            // What travels in the URL WhatsApp fetches the document with.
            // Opaque and unguessable rather than a signature over the id: it
            // keeps the link short, it survives rotating APP_KEY, deleting the
            // row revokes it, and the expiry stays a column something can
            // actually query.
            $table->string('pdf_token', 40)->unique();
            $table->timestamp('pdf_expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_plans');
    }
};
