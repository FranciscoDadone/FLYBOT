<?php

use App\Models\Airport;
use App\Support\AroAisDirectory;
use App\Support\GreatCircle;

it('encuentra la oficina del propio aeródromo', function () {
    $office = app(AroAisDirectory::class)->forIcao('SADF');

    expect($office?->name)->toBe('SAN FERNANDO')
        ->and($office?->email)->toBe('acfdo@eana.com.ar')
        ->and($office?->telephone)->not->toBeNull()
        ->and($office?->isControlCentre)->toBeFalse();
});

it('devuelve null para un código que no está en el directorio', function () {
    $directory = app(AroAisDirectory::class);

    expect($directory->forIcao('SAAG'))->toBeNull()
        ->and($directory->forIcao('XXXX'))->toBeNull()
        ->and($directory->forIcao(null))->toBeNull();
});

/*
 * Not every entry publishes an address: some offices are reachable only by
 * telephone. That is a fact about the office and not a gap in the data, so it
 * comes back as an office with no email rather than as nothing at all.
 */
it('devuelve la oficina aunque sólo tenga teléfono', function () {
    $office = app(AroAisDirectory::class)->forIcao('SAZG');

    expect($office)->not->toBeNull()
        ->and($office->telephone)->not->toBeNull()
        ->and($office->email)->toBeNull();
});

it('cae en el ACC de la FIR cuando el aeródromo no tiene oficina propia', function () {
    $airport = Airport::where('anac_code', 'ACB')->firstOrFail();
    $airport->fir = 'SAEF';

    $offices = app(AroAisDirectory::class)->forAirport($airport);

    expect($offices)->not->toBeEmpty()
        ->and($offices[0]->isControlCentre)->toBeTrue()
        ->and($offices[0]->icaoCode)->toBe('SAEF');
});

/*
 * `airports.fir` is filled in by a weekly import, not by the seed, so it can
 * legitimately be null on a fresh install. When it is, the answer is still an
 * office and not an apology: the nearest one that takes email.
 */
it('sin FIR cargada, ofrece igual la oficina con mail más cercana', function () {
    $airport = Airport::where('anac_code', 'ACB')->firstOrFail();
    $airport->fir = null;

    $offices = app(AroAisDirectory::class)->forAirport($airport);

    expect($offices)->not->toBeEmpty()
        ->and($offices[0]->isControlCentre)->toBeFalse()
        ->and($offices[0]->email)->not->toBeNull();
});

it('un aeródromo con oficina propia no ofrece nada más', function () {
    $offices = app(AroAisDirectory::class)->forAirport(Airport::where('icao_code', 'SADF')->firstOrFail());

    expect($offices)->toHaveCount(1)
        ->and($offices[0]->icaoCode)->toBe('SADF');
});

/*
 * A centre takes a plan over the telephone and publishes no address. The reply
 * has to be able to tell that apart from an office whose email we simply
 * failed to transcribe, which is what the flag is for.
 */
it('los ACC no tienen mail y se marcan como centro', function (string $fir) {
    $centre = app(AroAisDirectory::class)->forFir($fir);

    expect($centre)->not->toBeNull()
        ->and($centre->isControlCentre)->toBeTrue()
        ->and($centre->email)->toBeNull()
        ->and($centre->telephone)->not->toBeNull();
})->with(['SAEF', 'SACF', 'SAMF', 'SARR', 'SAVF']);

it('sugiere la oficina con mail más cercana, y ninguna si queda lejísimos', function () {
    $directory = app(AroAisDirectory::class);

    $pico = Airport::where('icao_code', 'SAZG')->firstOrFail();   // General Pico, La Pampa
    $nearest = $directory->nearestWithEmail($pico);

    expect($nearest)->not->toBeNull()
        ->and($nearest->email)->not->toBeNull();

    $office = Airport::where('icao_code', $nearest->icaoCode)->firstOrFail();

    expect(GreatCircle::distanceNm(
        (float) $pico->latitude, (float) $pico->longitude,
        (float) $office->latitude, (float) $office->longitude,
    ))->toBeLessThan(250.0);
});

/*
 * The list was transcribed by hand from a published directory, and a mistyped
 * address is a flight plan that never arrives. Nothing downstream would
 * notice, so this is where it gets noticed.
 */
it('mantiene el directorio consistente con el registro de aeródromos', function () {
    $directory = require resource_path('data/aro-ais.php');

    $known = Airport::whereIn('icao_code', array_keys($directory['offices']))
        ->pluck('icao_code')
        ->all();

    foreach ($directory['offices'] as $icao => $office) {
        expect($icao)->toMatch('/^S[AB][A-Z]{2}$/')
            ->and(in_array($icao, $known, true))
            ->toBeTrue("El directorio nombra {$icao}, que no está en el registro de aeródromos.");

        if ($office['email'] !== null) {
            // Todas las direcciones publicadas son ac + el código de tres
            // letras de la oficina. Las cuatro que la fuente escribe de dos
            // maneras distintas se resolvieron a favor de este patrón, así que
            // si alguna vuelve a copiarse mal, se ve acá.
            expect($office['email'])->toMatch('/^ac[a-z]{3}@eana\.com\.ar$/');
        }
    }

    // Los cinco ACC son centros de control, no aeródromos: no tienen por qué
    // estar en el registro, y de hecho no están.
    expect(array_keys($directory['centres']))->toBe(['SACF', 'SAEF', 'SAMF', 'SARR', 'SAVF']);
});
