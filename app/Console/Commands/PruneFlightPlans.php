<?php

namespace App\Console\Commands;

use App\Models\FlightPlan;
use App\Models\FlightPlanDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Clears out what a finished flight plan leaves behind.
 *
 * Three different lifetimes, because the three things have three different
 * reasons to exist. A draft goes stale in hours -- its date and its off-block
 * time stop being right. The PDF has to outlive the conversation, because a
 * pilot opens the attachment when they get to the aircraft, but not forever:
 * it sits behind a URL anyone holding the link can fetch. The row itself
 * outlives both, so that "the same flight, the other way" still works a week
 * later, and it keeps working after the file is gone because the plan is
 * rendered again from the values rather than from the document.
 */
class PruneFlightPlans extends Command
{
    protected $signature = 'fpl:prune';

    protected $description = 'Delete expired flight plan drafts and documents';

    public function handle(): int
    {
        $drafts = FlightPlanDraft::query()->expired()->delete();

        $documents = 0;

        FlightPlan::query()
            ->whereNotNull('pdf_path')
            ->where('pdf_expires_at', '<=', now())
            ->each(function (FlightPlan $plan) use (&$documents) {
                Storage::disk($plan->pdf_disk)->delete((string) $plan->pdf_path);

                // The row stays: repeating the flight is still worth offering,
                // and it regenerates the document from the values.
                $plan->forceFill(['pdf_path' => null])->save();

                $documents++;
            });

        $plans = FlightPlan::query()
            ->where('created_at', '<', now()->subDays((int) config('fpl.history_days')))
            ->delete();

        $this->info("Borradores vencidos: {$drafts}. Documentos borrados: {$documents}. Planes archivados: {$plans}.");

        return self::SUCCESS;
    }
}
