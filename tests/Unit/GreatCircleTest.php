<?php

use App\Models\Airport;
use App\Support\GreatCircle;

/*
 * Checked against published great-circle distances rather than against itself:
 * a formula that is wrong in a self-consistent way passes any test written
 * from its own output.
 */
it('mide distancias conocidas entre aeródromos argentinos', function (string $from, string $to, float $expectedNm) {
    $a = Airport::where('icao_code', $from)->firstOrFail();
    $b = Airport::where('icao_code', $to)->firstOrFail();

    expect(GreatCircle::distanceNm((float) $a->latitude, (float) $a->longitude, (float) $b->latitude, (float) $b->longitude))
        ->toBeGreaterThan($expectedNm * 0.98)
        ->toBeLessThan($expectedNm * 1.02);
})->with([
    'Ezeiza a Córdoba' => ['SAEZ', 'SACO', 355.0],
    'Ezeiza a Ushuaia' => ['SAEZ', 'SAWH', 1269.0],
    'San Fernando a Santa Rosa' => ['SADF', 'SAZR', 306.0],
]);

it('da cero para el mismo punto y es simétrica', function () {
    expect(GreatCircle::distanceNm(-34.6, -58.4, -34.6, -58.4))->toBe(0.0);

    $ida = GreatCircle::distanceNm(-34.45, -58.59, -36.59, -64.28);
    $vuelta = GreatCircle::distanceNm(-36.59, -64.28, -34.45, -58.59);

    expect(round($ida, 6))->toBe(round($vuelta, 6));
});

it('da el rumbo inicial en grados verdaderos, siempre entre 0 y 360', function () {
    // Hacia el norte por el mismo meridiano.
    expect(round(GreatCircle::initialBearing(-40.0, -60.0, -30.0, -60.0)))->toBe(0.0);
    // Hacia el sur.
    expect(round(GreatCircle::initialBearing(-30.0, -60.0, -40.0, -60.0)))->toBe(180.0);
    // Hacia el oeste sobre el ecuador.
    expect(round(GreatCircle::initialBearing(0.0, -60.0, 0.0, -70.0)))->toBe(270.0);
});
