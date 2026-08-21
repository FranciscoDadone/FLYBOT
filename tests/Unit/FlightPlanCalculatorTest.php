<?php

use App\Models\Airport;
use App\Services\FlightPlanCalculator;
use Carbon\CarbonImmutable;

function calculator(): FlightPlanCalculator
{
    return app(FlightPlanCalculator::class);
}

/** 4 de julio de 2026, 15:00 hora argentina (18:00 UTC). */
function reference(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-04 18:00:00', 'UTC');
}

/*
 * The single most expensive mistake this feature can make: item 13 is UTC and
 * a pilot says a local time. Three hours out is a plan filed for the wrong
 * slot.
 */
it('lee una hora sin sufijo como hora local argentina', function () {
    $at = calculator()->departureAt('1945', isUtc: false, dateText: null, now: reference());

    expect($at?->toIso8601String())->toBe('2026-07-04T22:45:00+00:00');
});

it('respeta una hora que el piloto dio en UTC', function () {
    $at = calculator()->departureAt('1945', isUtc: true, dateText: null, now: reference());

    expect($at?->toIso8601String())->toBe('2026-07-04T19:45:00+00:00');
});

it('pasa al día siguiente una hora local que ya pasó', function () {
    // Son las 15:00 locales: "las 9 y media" sólo puede ser mañana.
    $at = calculator()->departureAt('0930', isUtc: false, dateText: null, now: reference());

    expect($at?->format('Y-m-d H:i'))->toBe('2026-07-05 12:30');
});

it('entiende cómo se nombra el día', function (string $text, string $expected) {
    $at = calculator()->departureAt('1000', isUtc: false, dateText: $text, now: reference());

    expect($at?->format('Y-m-d'))->toBe($expected);
})->with([
    'hoy' => ['hoy', '2026-07-04'],
    'mañana' => ['mañana', '2026-07-05'],
    'manana sin tilde' => ['manana', '2026-07-05'],
    'pasado mañana' => ['pasado mañana', '2026-07-06'],
    'día y mes' => ['12/7', '2026-07-12'],
    'con año' => ['12/7/26', '2026-07-12'],
]);

it('rechaza una hora que no es una hora', function (string $hhmm) {
    expect(calculator()->departureAt($hhmm, isUtc: false, dateText: null, now: reference()))->toBeNull();
})->with(['2560', '1970', '19:45', '945', 'tarde']);

/*
 * The estimate is still-air and direct, and the reply that carries it says so.
 * The reference plan flies this leg in 0220 by airways; 306 nautical miles at
 * 175 knots direct is about 1:45 plus the climb allowance.
 */
it('estima el EET desde la distancia y la velocidad de crucero', function () {
    $sadf = Airport::where('icao_code', 'SADF')->firstOrFail();
    $sazr = Airport::where('icao_code', 'SAZR')->firstOrFail();

    $distance = calculator()->distanceBetween($sadf, $sazr);

    expect($distance)->toBeGreaterThan(300.0)->toBeLessThan(312.0)
        ->and(calculator()->eet($distance, 175))->toBe('0155');
});

it('no estima EET sin coordenadas ni sin velocidad', function () {
    $sadf = Airport::where('icao_code', 'SADF')->firstOrFail();
    $nowhere = new Airport(['anac_code' => 'XXX']);

    expect(calculator()->distanceBetween($sadf, $nowhere))->toBeNull()
        ->and(calculator()->eet(300.0, 0))->toBeNull();
});

it('propone autonomía como el EET más la reserva', function () {
    // 45 minutos de reserva por defecto.
    expect(calculator()->suggestedEndurance('0155'))->toBe('0240')
        ->and(calculator()->suggestedEndurance(null))->toBeNull();
});

/*
 * The date of flight is the UTC one. An evening departure from Argentina is
 * already the next day in UTC, and writing the local date there dates the plan
 * a day early.
 */
it('toma el DOF de la fecha UTC y no de la local', function () {
    $at = calculator()->departureAt('2200', isUtc: false, dateText: null, now: reference());

    expect($at?->format('Y-m-d H:i'))->toBe('2026-07-05 01:00')
        ->and(calculator()->dof($at))->toBe('260705');
});

it('arma el ítem 18 en el orden del formulario', function () {
    expect(calculator()->otherInformation('260704', 'Javier Catoni', 'B'))
        ->toBe('DOF/260704 OPR/JAVIERCATONI PER/B');

    expect(calculator()->otherInformation('260704', null, null))->toBe('DOF/260704');

    // Un aeródromo sin código OACI viaja como ZZZZ en el ítem 13 y se nombra acá.
    expect(calculator()->otherInformation('260704', 'Aeroclub', 'B', ['dep' => 'ACB Coronel Bogado']))
        ->toBe('DOF/260704 OPR/AEROCLUB DEP/ACB CORONEL BOGADO PER/B');
});
