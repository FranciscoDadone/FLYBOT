<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The token that ties a form sent into WhatsApp to the draft it was sent
     * for.
     *
     * A Flow comes back with whatever token it was sent with and nothing else
     * to identify it by. Without this, a form filled in after its draft
     * expired would land on whichever draft happened to be open by then --
     * which for a flight plan means a document about a different flight.
     */
    public function up(): void
    {
        Schema::table('flight_plan_drafts', function (Blueprint $table) {
            $table->string('flow_token', 64)->nullable()->unique()->after('asked');
        });
    }

    public function down(): void
    {
        Schema::table('flight_plan_drafts', function (Blueprint $table) {
            $table->dropUnique(['flow_token']);
            $table->dropColumn('flow_token');
        });
    }
};
