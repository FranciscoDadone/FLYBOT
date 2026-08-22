<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flight plan a pilot is in the middle of writing.
 *
 * This is the exception to something the rest of the bot is built on, so it is
 * worth stating plainly: WhatsappBotService is stateless. It answers each
 * message from scratch, and everything a tapped button needs travels inside
 * the button's own id. That works because every other answer is one question
 * and one reply.
 *
 * A flight plan is not. It has eighteen items, they arrive over several
 * messages, and "POB 3" only means anything against what came before it. That
 * will not fit in a button id, so it lives here -- one row per phone number,
 * with an expiry, the same shape metar_subscriptions already uses for the only
 * other thing that outlives an exchange. The bot's logic stays stateless; what
 * it reads is a row.
 *
 * The row is short-lived on purpose, and not for security. A draft goes stale:
 * after a few hours its date of flight and its off-block time are wrong, and
 * confirming it without re-reading it would produce a plan that is confidently
 * incorrect. Starting again is the better failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_plan_drafts', function (Blueprint $table) {
            $table->id();

            // One open draft per pilot. It is what lets the next message be
            // read as a correction without having to guess whether it is one.
            $table->string('phone')->unique();

            // collecting -> ready -> issued. An issued draft is kept a while
            // longer so that a correction arriving after the PDF ("POB 3")
            // lands on the plan it is about instead of opening a new one.
            $table->string('status', 12)->default('collecting');

            // FlightPlanFields as it stands.
            $table->json('data');

            // Which of those values the bot worked out rather than the pilot:
            // the EET, the date of flight, the suggested endurance. A field
            // leaves this list the moment it is corrected by hand, and stops
            // being recalculated from then on -- without it, an EET typed as
            // 0230 quietly goes back to 0150 on the next message and nobody
            // can see why.
            $table->json('auto')->nullable();

            // What the bot asked for last, so an answer of "3" can be read as
            // an answer to the question it was an answer to.
            $table->json('asked')->nullable();

            // Set when the draft was seeded from a previous plan, by "repeat"
            // or "the way back".
            $table->foreignId('source_plan_id')->nullable()->constrained('flight_plans')->nullOnDelete();

            $table->timestamp('expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_plan_drafts');
    }
};
