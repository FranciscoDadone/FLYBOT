<?php

use App\DataObjects\FlightPlanFields;
use Carbon\CarbonImmutable;

/*
 * merge() is the whole mechanism behind correcting a draft in passing, and the
 * distinction it rests on -- silence is not erasure -- is the one that costs
 * data when it is got wrong.
 */
it('deja intacto lo que el mensaje nuevo no menciona', function () {
    $draft = new FlightPlanFields(
        aircraftIdentification: 'LV-HAW',
        departureAerodrome: 'SADF',
        personsOnBoard: '2',
    );

    $merged = $draft->merge(new FlightPlanFields(personsOnBoard: '3'));

    expect($merged->personsOnBoard)->toBe('3')
        ->and($merged->aircraftIdentification)->toBe('LV-HAW')
        ->and($merged->departureAerodrome)->toBe('SADF');
});

it('vacía un campo sólo con el centinela explícito', function () {
    $draft = new FlightPlanFields(alternateAerodrome: 'SAZR', route: 'DCT');

    $cleared = $draft->merge(new FlightPlanFields(alternateAerodrome: FlightPlanFields::CLEAR));

    expect($draft->merge(new FlightPlanFields)->alternateAerodrome)->toBe('SAZR')
        ->and($cleared->resolved()->alternateAerodrome)->toBeNull()
        ->and($cleared->resolved()->route)->toBe('DCT');
});

/*
 * Un mensaje se lee varias veces y cada lectura se mergea sobre la anterior.
 * Si el centinela se resolviera en el camino, "sin alternativa" llegaría al
 * borrador como silencio -- que significa dejarlo como estaba.
 */
it('deja pasar el centinela por los merges intermedios', function () {
    $incoming = (new FlightPlanFields)
        ->merge(new FlightPlanFields(alternateAerodrome: FlightPlanFields::CLEAR))
        ->merge(new FlightPlanFields(level: 'F100'));

    expect($incoming->alternateAerodrome)->toBe(FlightPlanFields::CLEAR)
        ->and((new FlightPlanFields(alternateAerodrome: 'SAZR'))->merge($incoming)->resolved()->alternateAerodrome)
        ->toBeNull();
});

it('lista lo que falta en el orden en que conviene preguntarlo', function () {
    $missing = (new FlightPlanFields(aircraftIdentification: 'LV-HAW'))->missing();

    expect($missing)->not->toContain('aircraftIdentification')
        ->and($missing)->toContain('departureAerodrome')
        ->and(array_search('typeOfAircraft', $missing, true))
        ->toBeLessThan(array_search('personsOnBoard', $missing, true));
});

/*
 * An alternate is not required: a daylight VFR hop does not need one, and
 * refusing to print a plan without one would be inventing a rule the AIP does
 * not state.
 */
it('no exige alternativa para dar el plan por completo', function () {
    $complete = [];

    foreach (FlightPlanFields::REQUIRED as $field) {
        $complete[$field] = 'X';
    }

    $fields = new FlightPlanFields(...$complete);

    expect($fields->isReady())->toBeTrue()
        ->and($fields->alternateAerodrome)->toBeNull();
});

it('sobrevive a ida y vuelta por la base', function () {
    $fields = new FlightPlanFields(
        aircraftIdentification: 'LV-HAW',
        emergencyRadio: ['V', 'E'],
        dinghiesCovered: true,
        departureAt: CarbonImmutable::parse('2026-07-04 22:45:00', 'UTC'),
    );

    $restored = FlightPlanFields::fromStorage(json_decode((string) json_encode($fields->toStorage()), true));

    expect($restored->aircraftIdentification)->toBe('LV-HAW')
        ->and($restored->emergencyRadio)->toBe(['V', 'E'])
        ->and($restored->dinghiesCovered)->toBeTrue()
        ->and($restored->departureAt?->toIso8601String())->toBe($fields->departureAt->toIso8601String());
});

/*
 * A draft written before a field was renamed is worth keeping the rest of:
 * losing a whole plan because one key no longer exists is the worse failure.
 */
it('ignora claves guardadas que ya no existen en vez de romperse', function () {
    $restored = FlightPlanFields::fromStorage([
        'aircraftIdentification' => 'LV-HAW',
        'campoQueYaNoExiste' => 'algo',
    ]);

    expect($restored->aircraftIdentification)->toBe('LV-HAW');
});
