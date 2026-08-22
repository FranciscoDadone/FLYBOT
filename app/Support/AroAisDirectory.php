<?php

namespace App\Support;

use App\DataObjects\AroAisOffice;
use App\Models\Airport;
use Illuminate\Support\Collection;

/**
 * Which AIS office a flight plan leaving a given aerodrome gets presented to.
 *
 * Most of the aerodromes anyone files from have an office on the field, and
 * that is the whole answer. The ones that do not are the interesting case, and
 * there are two honest replies to it rather than one:
 *
 *   - the area control centre of the FIR the aerodrome sits in, which is the
 *     office with jurisdiction and takes a plan by telephone; and
 *   - the nearest office that publishes an address, for a pilot who would
 *     rather send an email than make a call.
 *
 * Both are offered because both are used. Picking one and calling it "the"
 * answer would be inventing a rule the AIP does not state.
 */
class AroAisDirectory
{
    /**
     * How far away an emailing office stops being a useful suggestion.
     *
     * Nothing official: past this the office is not "near" in any sense a
     * pilot would recognise, and naming it would read as an instruction rather
     * than a convenience. The FIR's centre still answers, so nobody is left
     * without somewhere to file.
     */
    protected const NEAREST_OFFICE_LIMIT_NM = 250.0;

    /** @var array{offices: array<string, array<string, ?string>>, centres: array<string, array<string, ?string>>} */
    protected array $directory;

    /** @var Collection<int, Airport>|null */
    protected ?Collection $located = null;

    public function __construct()
    {
        $this->directory = require resource_path('data/aro-ais.php');
    }

    /**
     * The office at $icaoCode, if there is one on the field.
     */
    public function forIcao(?string $icaoCode): ?AroAisOffice
    {
        if ($icaoCode === null || ! isset($this->directory['offices'][$icaoCode])) {
            return null;
        }

        return $this->office($icaoCode, $this->directory['offices'][$icaoCode]);
    }

    /**
     * The control centre of $fir, the five codes `airports.fir` stores.
     */
    public function forFir(?string $fir): ?AroAisOffice
    {
        if ($fir === null || ! isset($this->directory['centres'][$fir])) {
            return null;
        }

        return $this->office($fir, $this->directory['centres'][$fir], isControlCentre: true);
    }

    /**
     * Everywhere a plan leaving $airport can be presented, best first.
     *
     * Never empty in practice and allowed to be: an aerodrome with no office,
     * no FIR on file and nothing within range has no answer, and saying so
     * beats naming an office at random.
     *
     * @return array<int, AroAisOffice>
     */
    public function forAirport(Airport $airport): array
    {
        $own = $this->forIcao($airport->icao_code);

        if ($own !== null) {
            return [$own];
        }

        return array_values(array_filter([
            $this->forFir($airport->fir),
            $this->nearestWithEmail($airport),
        ]));
    }

    /**
     * The closest office that publishes an address, within range of $airport.
     *
     * Distance is great-circle over the coordinates `airports` already holds,
     * so an aerodrome without them simply has no nearest office rather than a
     * wrong one.
     */
    public function nearestWithEmail(Airport $airport): ?AroAisOffice
    {
        if ($airport->latitude === null || $airport->longitude === null) {
            return null;
        }

        $best = null;
        $bestDistance = self::NEAREST_OFFICE_LIMIT_NM;

        foreach ($this->locatedOffices() as $candidate) {
            if ($candidate->icao_code === $airport->icao_code) {
                continue;
            }

            $distance = GreatCircle::distanceNm(
                (float) $airport->latitude,
                (float) $airport->longitude,
                (float) $candidate->latitude,
                (float) $candidate->longitude,
            );

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best === null ? null : $this->forIcao($best->icao_code);
    }

    /**
     * The aerodromes that both host an emailing office and have coordinates,
     * memoised: forAirport() is called once per reply and this walk is the
     * same every time.
     *
     * @return Collection<int, Airport>
     */
    protected function locatedOffices(): Collection
    {
        return $this->located ??= Airport::query()
            ->whereIn('icao_code', array_keys(array_filter(
                $this->directory['offices'],
                fn (array $office) => $office['email'] !== null,
            )))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();
    }

    /**
     * @param  array<string, ?string>  $entry
     */
    protected function office(string $code, array $entry, bool $isControlCentre = false): AroAisOffice
    {
        return new AroAisOffice(
            icaoCode: $code,
            name: $entry['name'] ?? $code,
            email: $entry['email'],
            telephone: $entry['telephone'],
            isControlCentre: $isControlCentre,
        );
    }
}
