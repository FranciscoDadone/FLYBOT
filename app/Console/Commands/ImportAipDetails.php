<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\AipService;
use App\Support\AipAdDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Fills in what the AIP publishes for the aerodromes that have a ficha in it —
 * fuel, telephone, hours, the tower/approach frequency and the radio
 * navigation aids — rather than leaving the ficha to say "sin dato publicado
 * en MADHEL" about a fact the AIP has had all along.
 *
 * Companion to notams:import-airport-details, not a replacement: that one
 * still owns elevation, location and the rest, which MADHEL publishes for
 * every aerodrome including the delegated ones. This walks the ~50 with an
 * AD 2.0 document, one PDF each, which is why — unlike the MADHEL imports —
 * it does not need a concurrent pool to finish in reasonable time.
 *
 * Every aerodrome with a document, not only the delegated ones: is_aip_delegated
 * is MADHEL's grant of the ficha data, and the AIP publishes an AD 2 for a dozen
 * aerodromes it never granted — Morón, El Palomar, Rosario, Tandil. Their fuel
 * and telephone stay MADHEL's (see WhatsappBotService::airportServiceLines()),
 * but their frequency and radio aids exist nowhere else, so leaving them out of
 * this walk was leaving them out of the ficha entirely.
 */
class ImportAipDetails extends Command
{
    protected $signature = 'notams:import-aip-details
                            {--only= : Sólo estos códigos ANAC, separados por coma}';

    protected $description = "Import each aerodrome's fuel, telephone, horario, frecuencia ATS y radioayudas desde su ficha de la AIP.";

    public function handle(AipService $aip, Parser $parser): int
    {
        try {
            $documents = $aip->adDocuments();
        } catch (Throwable $e) {
            report($e);
            $this->error('No se pudo leer el listado de la AIP: '.$e->getMessage());

            return self::FAILURE;
        }

        $airports = $this->airports(array_keys($documents));

        if ($airports->isEmpty()) {
            $this->warn('No hay aeródromos con ficha en la AIP en el registro. Corré notams:import-airport-details primero.');

            return self::SUCCESS;
        }

        $rows = [];
        $withoutDocument = [];

        foreach ($airports as $airport) {
            $url = $airport->icao_code === null ? null : ($documents[$airport->icao_code] ?? null);

            if ($url === null) {
                $withoutDocument[] = $airport->anac_code;

                continue;
            }

            $details = $this->fetchDetails($aip, $parser, $url);

            if ($details === null) {
                continue;
            }

            $rows[] = [
                'anac_code' => $airport->anac_code,
                'name' => $airport->name,
                'aip_fuel' => $details['fuel'],
                'aip_telephone' => $details['telephone'] === null
                    ? null
                    : json_encode($details['telephone'], JSON_UNESCAPED_UNICODE),
                'aip_service_schedule' => $details['service_schedule'],
                'aip_ats_frequency' => $details['ats_frequency'],
                'aip_navaids' => $details['navaids'] === null
                    ? null
                    : json_encode($details['navaids'], JSON_UNESCAPED_UNICODE),
                'aip_details_updated_at' => now(),
                'updated_at' => now(),
            ];
        }

        Airport::upsert($rows, ['anac_code'], [
            'aip_fuel', 'aip_telephone', 'aip_service_schedule', 'aip_ats_frequency',
            'aip_navaids', 'aip_details_updated_at', 'updated_at',
        ]);

        $this->info(sprintf(
            '%d fichas AIP importadas de %d aeródromos (%d delegados sin documento AD-2.0 publicado).',
            count($rows),
            $airports->count(),
            count($withoutDocument),
        ));

        return self::SUCCESS;
    }

    /**
     * The aerodromes worth opening a PDF for: the ones the AIP publishes an
     * AD 2 for, plus every delegated one whether or not it appears in that
     * listing — a delegated aerodrome with no document is the anomaly the
     * summary line counts, and dropping it here would hide it.
     *
     * @param  array<int, string>  $withDocument  OACI codes the AIP lists an AD 2.0 for.
     * @return Collection<int, Airport>
     */
    protected function airports(array $withDocument): Collection
    {
        $query = Airport::query()
            ->realAerodromes()
            ->where(fn ($query) => $query
                ->where('is_aip_delegated', true)
                ->orWhereIn('icao_code', $withDocument))
            ->orderBy('anac_code');

        if ($only = $this->option('only')) {
            $query->whereIn('anac_code', array_map(
                fn (string $code) => strtoupper(trim($code)),
                explode(',', $only),
            ));
        }

        return $query->get();
    }

    /**
     * One aerodrome's fields off its AIP PDF.
     *
     * A PDF that fails to download or parse is skipped rather than retried,
     * same reasoning as a MADHEL request that comes back empty: the ficha
     * stays a week stale for that one aerodrome rather than the whole import
     * failing over a single bad document.
     *
     * @return array{fuel: string|null, telephone: array<int, string>|null, service_schedule: string|null, ats_frequency: string|null, navaids: array<int, array{type: string, id: string|null, frequency: string, unit: string, hours: string|null}>|null}|null
     */
    protected function fetchDetails(AipService $aip, Parser $parser, string $url): ?array
    {
        try {
            $bytes = $aip->download($url);
            $text = $parser->parseContent($bytes)->getText();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return AipAdDetails::parse($text);
    }
}
