<?php

namespace App\DataObjects;

use Carbon\CarbonImmutable;
use ReflectionClass;
use ReflectionParameter;

/**
 * One filled-in ICAO flight plan, a property per box on the form.
 *
 * Flat and explicit rather than nested, because the form is: item 19 is not a
 * sub-object to a pilot, it is the bottom half of the page. Keeping the shape
 * of the paper means the renderer, the questions the bot asks and the
 * corrections a pilot writes all talk about the same names.
 *
 * Every property is nullable, including the lists. A flight plan is assembled
 * a message at a time, so "not filled in yet" has to be representable at every
 * step -- and null is how merge() tells "the pilot did not mention this" apart
 * from "the pilot cleared this", which is CLEAR. Getting that distinction
 * wrong is what makes a correction wipe out the field next to the one being
 * fixed.
 */
final readonly class FlightPlanFields
{
    /**
     * What a pilot writes to empty a box that already has something in it.
     *
     * Only the deterministic parser produces this, from an explicit phrase
     * ("sin alternativa"). The model never does: asking it to tell silence
     * from erasure is asking for the one mistake that loses data.
     */
    public const CLEAR = "\x00clear";

    /**
     * What a flight plan cannot be filed without, in the order it is worth
     * asking for: the aircraft, then the flight, then the survival items.
     *
     * The alternates are deliberately absent. A daylight VFR hop does not need
     * one, and a bot that refuses to print a plan until it is given one would
     * be inventing a rule.
     *
     * @var array<int, string>
     */
    public const REQUIRED = [
        'aircraftIdentification',
        'typeOfAircraft',
        'wakeTurbulenceCat',
        'equipmentCom',
        'equipmentSurveillance',
        'flightRules',
        'typeOfFlight',
        'departureAerodrome',
        'departureTime',
        'destinationAerodrome',
        'cruisingSpeed',
        'level',
        'route',
        'totalEet',
        'endurance',
        'personsOnBoard',
        'aircraftColourAndMarkings',
        'pilotInCommand',
    ];

    /**
     * @param  array<int, string>|null  $emergencyRadio  Letters CARRIED, of U V E.
     * @param  array<int, string>|null  $survivalEquipment  Letters CARRIED, of S P D M J.
     * @param  array<int, string>|null  $jackets  Letters CARRIED, of J L F U V.
     */
    public function __construct(
        // Item 7
        public ?string $aircraftIdentification = null,
        // Item 8
        public ?string $flightRules = null,
        public ?string $typeOfFlight = null,
        // Item 9
        public ?string $number = null,
        public ?string $typeOfAircraft = null,
        public ?string $wakeTurbulenceCat = null,
        // Item 10
        public ?string $equipmentCom = null,
        public ?string $equipmentSurveillance = null,
        // Item 13
        public ?string $departureAerodrome = null,
        public ?string $departureTime = null,
        // Item 15
        public ?string $cruisingSpeed = null,
        public ?string $level = null,
        public ?string $route = null,
        // Item 16
        public ?string $destinationAerodrome = null,
        public ?string $totalEet = null,
        public ?string $alternateAerodrome = null,
        public ?string $alternateAerodrome2 = null,
        // Item 18
        public ?string $otherInformation = null,
        // Item 19
        public ?string $endurance = null,
        public ?string $personsOnBoard = null,
        public ?array $emergencyRadio = null,
        public ?array $survivalEquipment = null,
        public ?array $jackets = null,
        public ?string $dinghiesNumber = null,
        public ?string $dinghiesCapacity = null,
        public ?bool $dinghiesCovered = null,
        public ?string $dinghiesColour = null,
        public ?string $aircraftColourAndMarkings = null,
        public ?string $remarks = null,
        public ?string $pilotInCommand = null,
        // Header
        public ?string $filingTime = null,
        public ?string $originator = null,
        public ?string $filedBy = null,
        // Ours, never printed: the instant everything above is derived from,
        // and the ANAC codes behind aerodromes with no ICAO one of their own.
        public ?CarbonImmutable $departureAt = null,
        public ?string $departureAnacCode = null,
        public ?string $destinationAnacCode = null,
        public ?string $alternateAnacCode = null,
    ) {}

    /**
     * This plan with every value $other actually states written over it.
     *
     * The whole mechanism of correcting a draft in passing. A null in $other
     * means the message said nothing about that box and it keeps what it had;
     * CLEAR means the message emptied it on purpose. There is no third case,
     * which is why an extractor is allowed to return a mostly-null object and
     * still be useful.
     */
    public function merge(self $other): self
    {
        $merged = [];

        foreach ($other->toArray() as $key => $value) {
            $merged[$key] = match (true) {
                $value === null => $this->{$key},
                $value === self::CLEAR => null,
                default => $other->{$key},
            };
        }

        $merged['departureAt'] = $other->departureAt ?? $this->departureAt;

        return new self(...$merged);
    }

    /**
     * The required boxes still empty, in the order they are worth asking for.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $field) => $this->{$field} === null || $this->{$field} === '',
        ));
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Everything but the resolved instant, which is a Carbon and is restored
     * from the departure date and time instead of being stored twice.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = get_object_vars($this);

        unset($values['departureAt']);

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return array_filter(
            $this->toArray() + ['departureAt' => $this->departureAt?->toIso8601String()],
            fn (mixed $value) => $value !== null,
        );
    }

    /**
     * The name of every box on the form, in constructor order.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        static $names = null;

        return $names ??= array_map(
            fn (ReflectionParameter $parameter) => $parameter->getName(),
            (new ReflectionClass(self::class))->getConstructor()?->getParameters() ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromStorage(array $values): self
    {
        $departureAt = $values['departureAt'] ?? null;

        unset($values['departureAt']);

        // Anything the stored shape has that this class no longer does is
        // dropped rather than fatal: a draft written before a field was
        // renamed is worth keeping the rest of.
        $known = array_intersect_key($values, array_flip(self::names()));

        return new self(...[
            ...$known,
            'departureAt' => is_string($departureAt) ? CarbonImmutable::parse($departureAt) : null,
        ]);
    }
}
