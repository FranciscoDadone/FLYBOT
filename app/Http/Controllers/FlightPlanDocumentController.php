<?php

namespace App\Http\Controllers;

use App\Models\FlightPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over a generated flight plan.
 *
 * This exists because of how WhatsApp sends a document: Meta fetches the URL
 * itself, with none of our credentials, so the file has to be reachable from
 * the open internet for as long as the pilot might open the attachment.
 *
 * What keeps that from being an open door is the token -- forty random
 * characters, unguessable and belonging to one plan. Not a signed URL: this
 * keeps the link short, survives rotating APP_KEY, is revoked by deleting the
 * row, and leaves the expiry as a column the pruning command can actually
 * query. Nothing about the plan is derivable from it, and nothing enumerable
 * leads to one.
 */
class FlightPlanDocumentController extends Controller
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        $plan = FlightPlan::query()->where('pdf_token', $token)->first();

        // One 404 for every way of not being allowed to have it -- expired,
        // pruned, never existed -- so the response says nothing about which.
        abort_if($plan === null || ! $plan->hasDownloadableDocument(), 404);

        $disk = Storage::disk($plan->pdf_disk);

        abort_unless($disk->exists((string) $plan->pdf_path), 404);

        return $disk->download((string) $plan->pdf_path, $plan->filename(), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
