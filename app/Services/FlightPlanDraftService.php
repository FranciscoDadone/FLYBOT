<?php

namespace App\Services;

use App\DataObjects\FlightPlanFields;
use App\Models\AircraftProfile;
use App\Models\Airport;
use App\Models\FlightPlan;
use App\Models\FlightPlanDraft;
use App\Models\PilotProfile;
use App\Support\AirportResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Keeps the flight plan a pilot is writing, and decides what goes in it.
 *
 * The interesting part is the order things are layered in, because everything
 * else in the feature depends on getting it right:
 *
 *   1. what the form itself assumes (one aircraft, general aviation),
 *   2. what the registration means, from the aircraft profile,
 *   3. who the pilot is, from theirs,
 *   4. what the draft already said,
 *   5. what this message says,
 *   6. what can be worked out from all of the above.
 *
 * Each layer only fills what the one above it left empty, which is what makes
 * a correction land on one box and leave the rest alone. And step six is
 * conditional: a value the pilot has typed by hand stops being recalculated
 * from that moment on, tracked in the draft's `auto` list. Without that, an
 * EET corrected to 0230 quietly goes back to 0155 on the next message, and
 * there is no way for the pilot to see why.
 */
class FlightPlanDraftService
{
    /**
     * Fields the bot is allowed to work out on its own, and therefore the
     * fields it must stop working out once they have been corrected.
     *
     * @var array<int, string>
     */
    protected const COMPUTED = ['totalEet', 'endurance', 'otherInformation', 'filingTime'];

    public function __construct(
        protected AirportResolver $airports,
        protected FlightPlanCalculator $calculator,
    ) {}

    public function openFor(string $phone): ?FlightPlanDraft
    {
        return FlightPlanDraft::query()->where('phone', $phone)->active()->first();
    }

    public function hasOpen(string $phone): bool
    {
        return $this->openFor($phone) !== null;
    }

    /**
     * A fresh draft, seeded with whatever is already known about this pilot.
     */
    public function start(string $phone, ?FlightPlanFields $seed = null, ?FlightPlan $from = null): FlightPlanDraft
    {
        $draft = FlightPlanDraft::updateOrCreate(
            ['phone' => $phone],
            [
                'status' => FlightPlanDraft::COLLECTING,
                'data' => [],
                'auto' => [],
                'asked' => [],
                // A new plan on the same row: whatever form was sent for the
                // last one stops being answerable, which is the point. It was
                // about a different flight.
                'flow_token' => null,
                'source_plan_id' => $from?->id,
                'expires_at' => $this->expiry(),
            ],
        );

        return $this->apply($draft, $seed ?? new FlightPlanFields);
    }

    /**
     * Fold $incoming into $draft and work out everything that follows from it.
     */
    public function apply(FlightPlanDraft $draft, FlightPlanFields $incoming): FlightPlanDraft
    {
        $stated = $this->statedFields($incoming);

        $fields = $draft->fields()->merge($incoming);
        $fields = $this->withDefaults($draft->phone, $fields);

        // Anything this message set by hand leaves the computed list and stays
        // as the pilot wrote it.
        $auto = array_values(array_diff($draft->auto ?? [], $stated));

        [$fields, $auto] = $this->compute($fields, $auto);

        // Whatever the message emptied on purpose stops being a value here and
        // becomes an empty box. Once, at the end: every reading of the message
        // has had its say by now, and one of them may well have been the one
        // that said to empty it.
        $fields = $fields->resolved();

        $draft->fill([
            'data' => $fields->toStorage(),
            'auto' => $auto,
            'expires_at' => $this->expiry(),
            'status' => $fields->isReady() ? FlightPlanDraft::READY : FlightPlanDraft::COLLECTING,
        ])->save();

        return $draft;
    }

    /**
     * Remember what the bot just asked for, so a bare answer to it can be read
     * as one.
     *
     * @param  array<int, string>  $fields
     */
    public function remember(FlightPlanDraft $draft, array $fields): FlightPlanDraft
    {
        $draft->fill(['asked' => array_values($fields), 'expires_at' => $this->expiry()])->save();

        return $draft;
    }

    /**
     * The token a form sent for this draft comes back with.
     *
     * One per draft rather than one per message: a pilot who scrolls up and
     * fills in the form from a message sent ten minutes ago is answering the
     * same draft, and rejecting that would be inventing a rule nobody could
     * see. What bounds it is the draft's own life -- three hours, after which
     * there is nothing for a submission to land on.
     */
    public function flowToken(FlightPlanDraft $draft): string
    {
        if (blank($draft->flow_token)) {
            $draft->fill(['flow_token' => Str::random(32)])->save();
        }

        return (string) $draft->flow_token;
    }

    /**
     * The draft a submitted form belongs to, or null when it has outlived it.
     */
    public function byFlowToken(string $token): ?FlightPlanDraft
    {
        return $token === ''
            ? null
            : FlightPlanDraft::query()->where('flow_token', $token)->active()->first();
    }

    public function markIssued(FlightPlanDraft $draft): FlightPlanDraft
    {
        $draft->fill(['status' => FlightPlanDraft::ISSUED, 'expires_at' => $this->expiry()])->save();

        return $draft;
    }

    public function discard(string $phone): void
    {
        FlightPlanDraft::query()->where('phone', $phone)->delete();
    }

    /**
     * The aerodrome behind an ANAC indicator, for the coordinates an EET needs
     * and the ICAO code item 13 wants.
     */
    public function airport(?string $anacCode): ?Airport
    {
        return $anacCode === null ? null : $this->airports->find($anacCode);
    }

    /**
     * How an aerodrome is written in items 13 and 16.
     *
     * ICAO where there is one, ZZZZ where there is not -- which is most of the
     * strips general aviation flies out of here, and the reason the name then
     * has to go into item 18.
     */
    public function indicatorFor(?Airport $airport): ?string
    {
        return $airport === null ? null : ($airport->icao_code ?? 'ZZZZ');
    }

    protected function expiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours((int) config('fpl.draft_ttl_hours'));
    }

    /**
     * Which boxes $incoming actually says something about.
     *
     * @return array<int, string>
     */
    protected function statedFields(FlightPlanFields $incoming): array
    {
        return array_keys(array_filter(
            $incoming->toArray(),
            fn (mixed $value) => $value !== null,
        ));
    }

    /**
     * The form's own assumptions and this pilot's profiles, under whatever the
     * draft and the message already said.
     */
    protected function withDefaults(string $phone, FlightPlanFields $fields): FlightPlanFields
    {
        $base = new FlightPlanFields(
            // One aircraft, unless somebody says otherwise. A formation files
            // a number and is rare enough to be worth typing.
            number: '1',
            // General aviation: what a bot on WhatsApp is being asked for.
            typeOfFlight: 'G',
        );

        $aircraft = $this->aircraftProfile($phone, $fields->aircraftIdentification);

        if ($aircraft !== null) {
            $base = $base->merge(new FlightPlanFields(
                typeOfAircraft: $aircraft->aircraft_type,
                wakeTurbulenceCat: $aircraft->wake_turbulence,
                equipmentCom: $aircraft->equipment_com,
                equipmentSurveillance: $aircraft->equipment_surveillance,
                cruisingSpeed: $aircraft->cruising_speed,
                level: $aircraft->default_level,
                endurance: $aircraft->endurance,
                emergencyRadio: $aircraft->emergency_radio,
                survivalEquipment: $aircraft->survival_equipment,
                jackets: $aircraft->jackets,
                aircraftColourAndMarkings: $aircraft->colour_markings,
            ));
        }

        $pilot = PilotProfile::query()->where('phone', $phone)->first();

        if ($pilot !== null) {
            $base = $base->merge(new FlightPlanFields(
                pilotInCommand: $pilot->pilotInCommand(),
                filedBy: $pilot->filed_by ?? $pilot->pilotInCommand(),
            ));
        }

        $fields = $base->merge($fields);

        // Who filed it is the pilot unless somebody says otherwise, and on a
        // first plan there is no profile to have said so yet.
        return $fields->filedBy === null && $fields->pilotInCommand !== null
            ? $fields->merge(new FlightPlanFields(filedBy: $fields->pilotInCommand))
            : $fields;
    }

    public function aircraftProfile(string $phone, ?string $registration): ?AircraftProfile
    {
        if ($registration === null) {
            return null;
        }

        return AircraftProfile::query()->forPhone($phone)->matching($registration)->first();
    }

    /**
     * Everything that follows from the boxes already filled: the time en
     * route, a suggested endurance, item 18, and the filing time.
     *
     * @param  array<int, string>  $auto
     * @return array{0: FlightPlanFields, 1: array<int, string>}
     */
    protected function compute(FlightPlanFields $fields, array $auto): array
    {
        $computed = [];

        $mine = function (string $field) use ($fields, $auto): bool {
            return $fields->{$field} === null || in_array($field, $auto, true);
        };

        if ($mine('totalEet')) {
            $eet = $this->estimateEet($fields);

            if ($eet !== null) {
                $fields = $fields->merge(new FlightPlanFields(totalEet: $eet));
                $computed[] = 'totalEet';
            }
        }

        if ($mine('endurance')) {
            $endurance = $this->calculator->suggestedEndurance($fields->totalEet);

            if ($endurance !== null) {
                $fields = $fields->merge(new FlightPlanFields(endurance: $endurance));
                $computed[] = 'endurance';
            }
        }

        if ($mine('otherInformation') && $fields->departureAt !== null) {
            $fields = $fields->merge(new FlightPlanFields(
                otherInformation: $this->calculator->otherInformation(
                    $this->calculator->dof($fields->departureAt),
                    $fields->originator,
                    null,
                    $this->zzzzNames($fields),
                ),
            ));
            $computed[] = 'otherInformation';
        }

        // Always the moment of writing, never carried over from an earlier
        // pass: it is the time the plan was filed, not the time it was begun.
        $fields = $fields->merge(new FlightPlanFields(filingTime: $this->calculator->filingTime()));
        $computed[] = 'filingTime';

        return [$fields, array_values(array_unique([...array_intersect($auto, self::COMPUTED), ...$computed]))];
    }

    protected function estimateEet(FlightPlanFields $fields): ?string
    {
        $distance = $this->calculator->distanceBetween(
            $this->airport($fields->departureAnacCode),
            $this->airport($fields->destinationAnacCode),
        );

        $knots = $this->knots($fields->cruisingSpeed);

        return $distance === null || $knots === null ? null : $this->calculator->eet($distance, $knots);
    }

    protected function knots(?string $cruisingSpeed): ?int
    {
        return $cruisingSpeed !== null && preg_match('/^N(\d{4})$/', $cruisingSpeed, $m) === 1
            ? (int) $m[1]
            : null;
    }

    /**
     * The DEP/ and DEST/ that have to accompany a ZZZZ.
     *
     * @return array<string, string>
     */
    protected function zzzzNames(FlightPlanFields $fields): array
    {
        $names = [];

        foreach (['dep' => $fields->departureAnacCode, 'dest' => $fields->destinationAnacCode] as $key => $code) {
            $airport = $this->airport($code);

            if ($airport !== null && $airport->icao_code === null) {
                $names[$key] = trim($airport->anac_code.' '.$airport->name);
            }
        }

        return $names;
    }
}
