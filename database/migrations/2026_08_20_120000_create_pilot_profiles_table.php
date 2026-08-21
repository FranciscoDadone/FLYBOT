<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the pilot is, so the bot only has to ask once.
 *
 * Item 19 ends with the pilot in command and their licence, and the strip
 * under the form repeats it as who filed the plan. Neither ever changes, and
 * asking for a licence number on every flight is the fastest way to make a
 * feature nobody uses twice.
 *
 * Keyed by the phone number in the provider's own form, the same as
 * metar_subscriptions and whatsapp_messages, so everything the application
 * knows about one person lines up. There is no user account behind a WhatsApp
 * number and this deliberately does not invent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_profiles', function (Blueprint $table) {
            $table->id();

            $table->string('phone')->unique();

            $table->string('name')->nullable();

            // As it goes on the form: "PCA39941501". Never validated against
            // anything -- ANAC publishes no register we can check it in, and a
            // wrong licence is the pilot's to notice on the draft.
            $table->string('licence')->nullable();

            // Usually the pilot, occasionally an operator or a school filing
            // on their behalf, which is why it is its own field.
            $table->string('filed_by')->nullable();

            // Item 18's OPR/ and PER/, which belong to whoever operates the
            // flight rather than to the airframe.
            $table->string('default_operator')->nullable();
            $table->string('default_performance', 1)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_profiles');
    }
};
