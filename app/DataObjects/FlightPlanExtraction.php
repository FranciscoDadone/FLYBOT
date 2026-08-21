<?php

namespace App\DataObjects;

/**
 * What a model made of one message, before any of it is trusted.
 *
 * The boxes that can be filled straight in are already in $fields. The rest is
 * kept as the words the pilot wrote, because resolving them is somebody else's
 * job and doing it here would be doing it twice: an aerodrome goes through
 * AirportResolver, which knows the register, and a time goes through
 * FlightPlanCalculator, which knows what UTC is.
 */
final readonly class FlightPlanExtraction
{
    public function __construct(
        public FlightPlanFields $fields,
        public ?string $departureText = null,
        public ?string $destinationText = null,
        public ?string $alternateText = null,
        public ?string $departureTime = null,
        public ?bool $timeIsUtc = null,
        public ?string $dateText = null,
    ) {}

    public function saysNothing(): bool
    {
        return $this->fields->toStorage() === []
            && $this->departureText === null
            && $this->destinationText === null
            && $this->alternateText === null
            && $this->departureTime === null;
    }
}
