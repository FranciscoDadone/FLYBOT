<?php

namespace App\Services;

use App\Ai\Agents\FlightPlanExtractorAgent;
use App\DataObjects\FlightPlanExtraction;
use App\DataObjects\FlightPlanFields;
use App\Models\FlightPlanDraft;
use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Runs a pilot's message past the model, and does not believe a word of it.
 *
 * Everything that comes back is validated against the shape the box actually
 * takes -- four digits for a duration, a real level, a registration that looks
 * like one -- and anything that fails is dropped rather than stored. A flight
 * plan is presented to an AIS office and flown against; a value that is wrong
 * but well-formed is the worst outcome available here, because it is the one
 * nobody re-reads.
 *
 * Not cached, unlike the NOTAM decoder. Every message is different, and two
 * pilots writing the same words are writing about two different flights.
 *
 * Without an API key configured this returns null and the deterministic parser
 * carries the whole feature, which it is built to do. A missing key is a
 * deployment state, not an error -- same as AiNotamDecoderService.
 */
class FlightPlanExtractor
{
    /**
     * Bump when the instructions change in a way that changes what comes back,
     * so a reading can be traced to the prompt that produced it.
     */
    public const PROMPT_VERSION = 1;

    public function __construct(protected FlightPlanCalculator $calculator) {}

    public function isAvailable(): bool
    {
        return ! blank(config('ai.providers.openrouter.key'));
    }

    /**
     * @param  array<int, string>  $asked  The boxes the bot last asked about.
     */
    public function extract(string $message, FlightPlanDraft $draft, array $asked = []): ?FlightPlanExtraction
    {
        if (! $this->isAvailable() || trim($message) === '') {
            return null;
        }

        try {
            $response = FlightPlanExtractorAgent::make()->prompt($this->prompt($message, $draft, $asked));
        } catch (Throwable $e) {
            // The parser still reads what it can and the bot still asks for
            // the rest, so a provider outage costs quality and not the
            // feature.
            report($e);

            return null;
        }

        $raw = $response instanceof StructuredAgentResponse ? $response->toArray() : [];

        return $raw === [] ? null : $this->interpret($raw);
    }

    /**
     * @param  array<int, string>  $asked
     */
    protected function prompt(string $message, FlightPlanDraft $draft, array $asked): string
    {
        $now = CarbonImmutable::now((string) config('app.display_timezone'));

        $prompt = "Today is {$now->format('Y-m-d')} ({$now->locale('en')->dayName}) and the local time is "
            ."{$now->format('H:i')}. Local time in Argentina is UTC-3.\n\n";

        $current = array_filter($draft->fields()->toStorage(), fn (mixed $v) => $v !== null);

        $prompt .= $current === []
            ? "The draft is empty: this message is starting a new flight plan.\n\n"
            : "Current draft, which this message may be correcting:\n"
                .json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n";

        if ($asked !== []) {
            $prompt .= 'The bot just asked the pilot for: '.implode(', ', array_slice($asked, 0, 3))."\n\n";
        }

        return $prompt."Pilot message:\n\"{$message}\"";
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    protected function interpret(array $raw): FlightPlanExtraction
    {
        $string = fn (string $key) => is_string($raw[$key] ?? null) && trim($raw[$key]) !== '' ? trim($raw[$key]) : null;

        $letters = function (string $key, array $allowed) use ($raw): ?array {
            $values = $raw[$key] ?? null;

            if (! is_array($values) || $values === []) {
                return null;
            }

            $kept = array_values(array_intersect(
                array_map(fn (mixed $v) => strtoupper((string) $v), $values),
                $allowed,
            ));

            return $kept === [] ? null : $kept;
        };

        $knots = is_numeric($raw['cruising_speed_kt'] ?? null) ? (int) $raw['cruising_speed_kt'] : null;

        return new FlightPlanExtraction(
            fields: new FlightPlanFields(
                aircraftIdentification: $this->registration($string('aircraft_identification')),
                flightRules: $this->oneOf($string('flight_rules'), ['I', 'V', 'Y', 'Z']),
                typeOfFlight: $this->oneOf($string('type_of_flight'), ['S', 'N', 'G', 'M', 'X']),
                typeOfAircraft: $this->designator($string('type_of_aircraft')),
                wakeTurbulenceCat: $this->oneOf($string('wake_turbulence'), ['L', 'M', 'H', 'J']),
                equipmentCom: $this->upper($string('equipment_com'), 20),
                equipmentSurveillance: $this->upper($string('equipment_surveillance'), 20),
                cruisingSpeed: $knots !== null && $knots > 0 && $knots < 3000
                    ? 'N'.str_pad((string) $knots, 4, '0', STR_PAD_LEFT)
                    : null,
                level: $this->level($string('level')),
                route: $this->upper($string('route'), 200),
                totalEet: $this->duration($string('total_eet')),
                endurance: $this->duration($string('endurance')),
                personsOnBoard: $this->personsOnBoard($string('persons_on_board')),
                emergencyRadio: $letters('emergency_radio', ['U', 'V', 'E']),
                survivalEquipment: $letters('survival_equipment', ['S', 'P', 'D', 'M', 'J']),
                jackets: $letters('jackets', ['J', 'L', 'F', 'U', 'V']),
                aircraftColourAndMarkings: $this->upper($string('aircraft_colour_and_markings'), 120),
                remarks: $this->upper($string('remarks'), 200),
                pilotInCommand: $this->upper($string('pilot_in_command'), 120),
                originator: $string('operator'),
            ),
            departureText: $string('departure_text'),
            destinationText: $string('destination_text'),
            alternateText: $string('alternate_text'),
            departureTime: $this->clock($string('departure_time')),
            timeIsUtc: is_bool($raw['departure_time_is_utc'] ?? null) ? $raw['departure_time_is_utc'] : null,
            dateText: $string('departure_date_text'),
        );
    }

    protected function registration(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        // Argentine registrations are two letters and three more. Anything
        // else is the model having produced something rather than read it.
        return preg_match('/^([A-Z]{2})([A-Z]{3})$/', $clean, $m) === 1 ? $m[1].'-'.$m[2] : null;
    }

    protected function designator(?string $value): ?string
    {
        $clean = $value === null ? null : strtoupper(trim($value));

        return $clean !== null && preg_match('/^[A-Z0-9]{2,4}$/', $clean) === 1 ? $clean : null;
    }

    protected function level(?string $value): ?string
    {
        $clean = $value === null ? null : strtoupper((string) preg_replace('/\s+/', '', $value));

        return $clean !== null && preg_match('/^(F\d{3}|A\d{3}|VFR)$/', $clean) === 1 ? $clean : null;
    }

    protected function duration(?string $value): ?string
    {
        return $value !== null && preg_match('/^(\d{2})([0-5]\d)$/', $value) === 1 ? $value : null;
    }

    protected function clock(?string $value): ?string
    {
        return $value !== null && preg_match('/^([01]\d|2[0-3])([0-5]\d)$/', $value) === 1 ? $value : null;
    }

    protected function personsOnBoard(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{1,3}(\/\d{1,3})?$/', trim($value)) === 1 ? trim($value) : null;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    protected function oneOf(?string $value, array $allowed): ?string
    {
        $clean = $value === null ? null : strtoupper(trim($value));

        return $clean !== null && in_array($clean, $allowed, true) ? $clean : null;
    }

    protected function upper(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $value)));

        return $clean === '' || mb_strlen($clean) > $max ? null : $clean;
    }
}
