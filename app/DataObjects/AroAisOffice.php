<?php

namespace App\DataObjects;

/**
 * Where a flight plan gets presented: one AIS office, or the area control
 * centre an aerodrome without its own office falls to.
 *
 * The distinction matters to the reader and not to the code, which is why it
 * is a flag rather than two classes: a centre takes a plan by telephone and
 * publishes no address, so a reply that offers one has to say so instead of
 * showing an empty line where the email should be.
 */
final readonly class AroAisOffice
{
    public function __construct(
        public string $icaoCode,
        public string $name,
        public ?string $email,
        public ?string $telephone,
        public bool $isControlCentre = false,
    ) {}
}
