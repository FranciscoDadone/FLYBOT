<?php

namespace App\Services;

use App\Models\Airport;
use App\Support\GreatCircle;
use Carbon\CarbonImmutable;

/**
 * The parts of a flight plan the bot can work out instead of asking for.
 *
 * Everything here is a proposal. Not one of these values reaches a PDF without
 * the pilot having seen it on the draft card first, and the ones that are
 * estimates say so where they are shown -- an EET is a number somebody plans
 * fuel against, and a bot that produces one silently is worse than a bot that
 * asks.
 */
class FlightPlanCalculator
{
    /**
     * Argentina keeps UTC-3 all year, but the offset is read from the zone
     * rather than assumed: the country has changed its mind about summer time
     * several times, and a hard-coded three would be wrong the next time.
     */
    protected string $timezone;

    public function __construct()
    {
        $this->timezone = (string) config('app.display_timezone', 'America/Argentina/Buenos_Aires');
    }

    /**
     * When the aircraft goes off blocks, in UTC.
     *
     * The single trap in this whole form. A pilot writes "19:45" meaning local
     * time; item 13 is UTC. Getting it backwards files a plan three hours out,
     * which is why this is deterministic here rather than left to a model, and
     * why the draft card shows both.
     *
     * @param  string  $hhmm  Clock time as four digits.
     * @param  bool  $isUtc  True only when the pilot actually said Z, UTC or Zulu.
     * @param  string|null  $dateText  "hoy", "mañana", "4/7" -- null means work it out.
     */
    public function departureAt(string $hhmm, bool $isUtc, ?string $dateText, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if (preg_match('/^(\d{2})(\d{2})$/', $hhmm, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        $now ??= CarbonImmutable::now('UTC');
        $zone = $isUtc ? 'UTC' : $this->timezone;
        $local = $now->setTimezone($zone);

        $date = $this->resolveDate($dateText, $local);

        $departure = $date
            ->setTime((int) $m[1], (int) $m[2])
            ->setTimezone('UTC');

        // No date was given and the time has already gone by today: nobody
        // writes "19:45" at nine in the evening meaning yesterday.
        if ($dateText === null && $departure->lessThan($now->subMinutes(30))) {
            $departure = $departure->addDay();
        }

        return $departure;
    }

    /**
     * Estimated time en route, as the form wants it: HHMM.
     *
     * Still air and a straight line, plus a fixed allowance for climb and
     * descent. Neither wind nor an airway routing is modelled, so this reads
     * low against a real plan -- the reference plan this was built from flies
     * 306 nautical miles direct and 408 by airways. It is a starting point the
     * pilot corrects, and every reply that carries one says so.
     */
    public function eet(float $distanceNm, int $tasKt): ?string
    {
        if ($tasKt <= 0 || $distanceNm <= 0) {
            return null;
        }

        $minutes = $distanceNm / $tasKt * 60 + (int) config('fpl.climb_descent_minutes');

        return $this->toHhmm((int) (ceil($minutes / 5) * 5));
    }

    /**
     * Fuel endurance to propose: the time en route plus a reserve.
     *
     * A suggestion and nothing more. What an aircraft can actually stay up for
     * is a fact about its tanks, and the profile is where that belongs -- this
     * only fills the gap until there is one.
     */
    public function suggestedEndurance(?string $eet): ?string
    {
        $minutes = $this->fromHhmm($eet);

        return $minutes === null
            ? null
            : $this->toHhmm($minutes + (int) config('fpl.reserve_minutes'));
    }

    /**
     * Item 18's date of flight: the UTC date, which is not always the local
     * one for an evening departure.
     */
    public function dof(CarbonImmutable $departureUtc): string
    {
        return $departureUtc->setTimezone('UTC')->format('ymd');
    }

    public function filingTime(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC')->format('Hi');
    }

    /**
     * The great-circle distance between two aerodromes, or null when either
     * one has no coordinates on file -- in which case there is no EET to
     * propose and the bot asks for it.
     */
    public function distanceBetween(?Airport $from, ?Airport $to): ?float
    {
        if ($from?->latitude === null || $from->longitude === null
            || $to?->latitude === null || $to->longitude === null) {
            return null;
        }

        return GreatCircle::distanceNm(
            (float) $from->latitude,
            (float) $from->longitude,
            (float) $to->latitude,
            (float) $to->longitude,
        );
    }

    /**
     * Item 18, assembled in the order the form expects it.
     *
     * DEP/ and DEST/ only appear when an aerodrome has no ICAO code of its
     * own, which item 13 writes as ZZZZ. Most of general aviation in Argentina
     * flies out of strips like that, so this is the ordinary case and not the
     * exception.
     *
     * @param  array<string, string>  $extras
     */
    public function otherInformation(string $dof, ?string $operator, ?string $performance, array $extras = []): string
    {
        $parts = ["DOF/{$dof}"];

        if ($operator !== null && trim($operator) !== '') {
            $parts[] = 'OPR/'.strtoupper((string) preg_replace('/\s+/', '', $operator));
        }

        foreach ($extras as $key => $value) {
            $parts[] = strtoupper($key).'/'.strtoupper($value);
        }

        if ($performance !== null && trim($performance) !== '') {
            $parts[] = 'PER/'.strtoupper($performance);
        }

        return implode(' ', $parts);
    }

    /**
     * The day a departure falls on, from however the pilot referred to it.
     *
     * Unrecognised text means today rather than an error: the time is the part
     * that matters, the date is almost always today or tomorrow, and the draft
     * card shows the resolved date for the pilot to correct.
     */
    protected function resolveDate(?string $text, CarbonImmutable $local): CarbonImmutable
    {
        $text = trim(mb_strtolower((string) $text));

        if ($text === '') {
            return $local;
        }

        if (str_contains($text, 'pasado')) {
            return $local->addDays(2);
        }

        if (str_contains($text, 'manana') || str_contains($text, 'mañana')) {
            return $local->addDay();
        }

        if (str_contains($text, 'hoy')) {
            return $local;
        }

        // "4/7", "04/07", "4-7-26": day and month the way they are written
        // here, never month first.
        if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?/', $text, $m) === 1) {
            $year = match (true) {
                ! isset($m[3]) => $local->year,
                strlen($m[3]) === 2 => 2000 + (int) $m[3],
                default => (int) $m[3],
            };

            $date = $local->setDate($year, (int) $m[2], (int) $m[1]);

            // A bare day and month that has already gone by means next year.
            if (! isset($m[3]) && $date->lessThan($local->subDay())) {
                $date = $date->addYear();
            }

            return $date;
        }

        foreach (self::WEEKDAYS as $name => $number) {
            if (str_contains($text, $name)) {
                return $local->next($number);
            }
        }

        return $local;
    }

    /**
     * Accent-stripped, because the message reaching here has not necessarily
     * been normalised and "miércoles" is usually typed without one anyway.
     *
     * @var array<string, int>
     */
    protected const WEEKDAYS = [
        'lunes' => CarbonImmutable::MONDAY,
        'martes' => CarbonImmutable::TUESDAY,
        'miercoles' => CarbonImmutable::WEDNESDAY,
        'miércoles' => CarbonImmutable::WEDNESDAY,
        'jueves' => CarbonImmutable::THURSDAY,
        'viernes' => CarbonImmutable::FRIDAY,
        'sabado' => CarbonImmutable::SATURDAY,
        'sábado' => CarbonImmutable::SATURDAY,
        'domingo' => CarbonImmutable::SUNDAY,
    ];

    protected function toHhmm(int $minutes): string
    {
        return sprintf('%02d%02d', intdiv($minutes, 60), $minutes % 60);
    }

    protected function fromHhmm(?string $hhmm): ?int
    {
        if ($hhmm === null || preg_match('/^(\d{2})(\d{2})$/', $hhmm, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
