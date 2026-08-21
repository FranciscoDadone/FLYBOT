<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a pilot's message into the boxes of an ICAO flight plan.
 *
 * Three rules shape everything below, and each one is there because the
 * obvious alternative is dangerous:
 *
 * It never resolves an aerodrome. It hands back the words the pilot used and
 * AirportResolver does the matching, because that already knows all 717 of
 * them, their names and their nicknames, and a hallucinated SAZR sends
 * somebody to the wrong province.
 *
 * It never does date or timezone arithmetic. It reports the clock time and
 * whether the pilot marked it as UTC; FlightPlanCalculator converts. Item 13
 * is UTC and pilots speak local time, and that conversion is too consequential
 * to depend on a model remembering an offset.
 *
 * Null is the answer for anything the message did not mention. That is what
 * makes the same agent work for the first message and for a one-line
 * correction: what comes back is merged over the draft, and a null leaves the
 * box alone.
 */
class FlightPlanExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You extract ICAO flight plan fields from a WhatsApp message written by a
        pilot in Argentine Spanish. The plan is filed in Argentina, on the EANA
        form.

        Return ONLY what the message actually states. Every field you are not
        told about must be null. Null means "the message said nothing about
        this" and leaves whatever the draft already had; it is never a guess and
        never an erasure. This matters most when the message is a correction to
        a draft you are shown: return the one or two fields it changes and null
        for everything else.

        Never invent. If the message does not give a registration, a licence
        number, an endurance or a time en route, those stay null — a plausible
        value in a flight plan is worse than a missing one, because nobody
        re-reads a box that is already filled in.

        This applies to flight_rules and type_of_flight above all. Do NOT infer
        them from a flight level, an aircraft type, a route or anything else
        about the flight. Return them only when the message uses the words:
        "IFR", "VFR", "instrumentos", "visual". Otherwise null.

        Aerodromes: return the words the pilot used, verbatim, in
        departure_text / destination_text / alternate_text. Do NOT translate
        them into ICAO codes, do not correct their spelling, and do not expand
        abbreviations. Something else resolves them against the real register.
        If the pilot wrote a code, return the code; if they wrote "San
        Fernando", return "San Fernando".

        Times: departure_time is the clock time as four digits, exactly the
        hour the pilot said. Set departure_time_is_utc true ONLY if they
        explicitly wrote Z, UTC or Zulu — Argentine pilots say local time by
        default and the conversion is done elsewhere. departure_date_text is
        however they referred to the day ("mañana", "el viernes", "12/7"),
        verbatim; do not resolve it to a date.

        Aircraft type must be an ICAO type designator (C208, PA28, C172, AT502).
        If the pilot named a model you cannot map with certainty — "una Cessna",
        "el Piper" — return null and let the bot ask.

        Durations (total_eet, endurance) are four digits, HHMM. "5 horas" is
        "0500", "2:20" is "0220".

        Item 19 lists what is CARRIED: emergency_radio from U (UHF), V (VHF),
        E (ELT); survival_equipment from S P D M J; jackets from J L F U V.
        Return them only if the message says what is aboard.
        TEXT;
    }

    public function provider(): string
    {
        return 'openrouter';
    }

    /**
     * Every field is nullable and required at once: required so a strict
     * provider always returns the whole object, nullable so it can say the
     * message was silent about a box. That pair is what the merge depends on.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $text = fn (string $description) => $schema->string()->nullable()->required()->description($description);

        $letters = fn (array $allowed, string $description) => $schema->array()
            ->items($schema->string()->enum($allowed))
            ->nullable()->required()->description($description);

        return [
            'aircraft_identification' => $text('Item 7. Registration as written, e.g. "LV-HAW".'),
            'flight_rules' => $schema->string()->enum(['I', 'V', 'Y', 'Z'])->nullable()->required()
                ->description('Item 8. IFR is I, VFR is V.'),
            'type_of_flight' => $schema->string()->enum(['S', 'N', 'G', 'M', 'X'])->nullable()->required()
                ->description('Item 8. General aviation is G.'),
            'type_of_aircraft' => $text('Item 9. ICAO type designator only, e.g. "C208". Null if unsure.'),
            'wake_turbulence' => $schema->string()->enum(['L', 'M', 'H', 'J'])->nullable()->required()
                ->description('Item 9.'),
            'equipment_com' => $text('Item 10, before the slash, e.g. "SDG".'),
            'equipment_surveillance' => $text('Item 10, after the slash, e.g. "C" or "N".'),
            'departure_text' => $text('Item 13. The departure aerodrome AS THE PILOT NAMED IT, verbatim. Never an ICAO code you worked out yourself.'),
            'destination_text' => $text('Item 16. The destination as the pilot named it, verbatim.'),
            'alternate_text' => $text('Item 16. The alternate as the pilot named it, verbatim.'),
            'departure_time' => $schema->string()->pattern('^[0-9]{4}$')->nullable()->required()
                ->description('Item 13. Clock time as four digits, exactly as the pilot said it.'),
            'departure_time_is_utc' => $schema->boolean()->nullable()->required()
                ->description('True only if the pilot explicitly wrote Z, UTC or Zulu.'),
            'departure_date_text' => $text('However the pilot referred to the day, verbatim. Do not resolve it.'),
            'cruising_speed_kt' => $schema->integer()->nullable()->required()
                ->description('Item 15. True airspeed in knots.'),
            'level' => $text('Item 15. "F100" for a flight level, "A045" for hundreds of feet, or "VFR".'),
            'route' => $text('Item 15. "DCT" for direct, otherwise the airways and fixes as given.'),
            'total_eet' => $schema->string()->pattern('^[0-9]{4}$')->nullable()->required()
                ->description('Item 16. HHMM.'),
            'endurance' => $schema->string()->pattern('^[0-9]{4}$')->nullable()->required()
                ->description('Item 19. HHMM.'),
            'persons_on_board' => $text('Item 19. A number, or "crew/passengers" as "1/2".'),
            'emergency_radio' => $letters(['U', 'V', 'E'], 'Item 19. Radios CARRIED.'),
            'survival_equipment' => $letters(['S', 'P', 'D', 'M', 'J'], 'Item 19. Survival equipment CARRIED.'),
            'jackets' => $letters(['J', 'L', 'F', 'U', 'V'], 'Item 19. Jackets CARRIED.'),
            'aircraft_colour_and_markings' => $text('Item 19, e.g. "blanco con azul".'),
            'pilot_in_command' => $text('Item 19. Name and licence as one line.'),
            'operator' => $text('Item 18 OPR/, when the message names an operator.'),
            'remarks' => $text('Item 19 observations, if any.'),
        ];
    }
}
