<?php

use App\Ai\Agents\FlightPlanExtractorAgent;
use App\Models\FlightPlanDraft;
use App\Services\FlightPlanExtractor;
use Carbon\CarbonImmutable;
use Laravel\Ai\Prompts\AgentPrompt;

function emptyDraft(array $data = []): FlightPlanDraft
{
    return new FlightPlanDraft([
        'phone' => 'whatsapp:+5491111111111',
        'status' => FlightPlanDraft::COLLECTING,
        'data' => $data,
        'expires_at' => CarbonImmutable::now()->addHours(3),
    ]);
}

beforeEach(function () {
    config(['ai.providers.openrouter.key' => 'test-key']);
});

/*
 * A missing key is a deployment state and not an error: the deterministic
 * parser carries the whole feature without one, which is what it is built to
 * do.
 */
it('no llama al modelo si no hay credencial', function () {
    withoutAi();

    FlightPlanExtractorAgent::fake();

    expect(app(FlightPlanExtractor::class)->extract('plan LV-HAW a Santa Rosa', emptyDraft()))->toBeNull();

    FlightPlanExtractorAgent::assertNeverPrompted();
});

it('lee un plan de un mensaje en castellano corriente', function () {
    FlightPlanExtractorAgent::fake([[
        'aircraft_identification' => 'LV-HAW',
        'type_of_aircraft' => 'C208',
        'wake_turbulence' => 'L',
        'flight_rules' => 'I',
        'departure_text' => 'San Fernando',
        'destination_text' => 'Santa Rosa',
        'departure_time' => '1945',
        'departure_time_is_utc' => false,
        'departure_date_text' => 'mañana',
        'cruising_speed_kt' => 175,
        'level' => 'F100',
        'persons_on_board' => '2',
        'aircraft_colour_and_markings' => 'blanco con azul',
    ]]);

    $result = app(FlightPlanExtractor::class)->extract('un Caravan blanco con azul, salgo de San Fernando a Santa Rosa mañana a las ocho menos cuarto de la noche', emptyDraft());

    expect($result?->fields->aircraftIdentification)->toBe('LV-HAW')
        ->and($result->fields->typeOfAircraft)->toBe('C208')
        ->and($result->fields->cruisingSpeed)->toBe('N0175')
        ->and($result->fields->aircraftColourAndMarkings)->toBe('BLANCO CON AZUL')
        ->and($result->departureText)->toBe('San Fernando')
        ->and($result->destinationText)->toBe('Santa Rosa')
        ->and($result->departureTime)->toBe('1945')
        ->and($result->timeIsUtc)->toBeFalse()
        ->and($result->dateText)->toBe('mañana');
});

/*
 * The whole reason the same agent works for a first message and for a
 * correction: null is "the message said nothing about this", and it leaves the
 * box alone rather than emptying it.
 */
it('devuelve sólo lo que la corrección cambia', function () {
    FlightPlanExtractorAgent::fake([['persons_on_board' => '3']]);

    $result = app(FlightPlanExtractor::class)->extract('somos 3', emptyDraft(['aircraftIdentification' => 'LV-HAW']));

    expect($result?->fields->personsOnBoard)->toBe('3')
        ->and($result->fields->aircraftIdentification)->toBeNull()
        ->and($result->fields->destinationAerodrome)->toBeNull();
});

/*
 * The model is never believed on its own. A well-formed but wrong value in a
 * flight plan is the worst outcome available, because it is the one nobody
 * re-reads.
 */
it('descarta lo que el modelo devuelve mal formado', function (array $raw, string $field) {
    FlightPlanExtractorAgent::fake([$raw]);

    $result = app(FlightPlanExtractor::class)->extract('lo que sea', emptyDraft());

    expect($result?->fields->{$field})->toBeNull();
})->with([
    'matrícula inventada' => [['aircraft_identification' => 'un Cessna'], 'aircraftIdentification'],
    'nivel imposible' => [['level' => 'muy alto'], 'level'],
    'EET que no es HHMM' => [['total_eet' => '2 horas'], 'totalEet'],
    'autonomía con minutos de más' => [['endurance' => '0575'], 'endurance'],
    'estela que no existe' => [['wake_turbulence' => 'X'], 'wakeTurbulenceCat'],
    'tipo demasiado largo' => [['type_of_aircraft' => 'CESSNA CARAVAN'], 'typeOfAircraft'],
    'personas que no son un número' => [['persons_on_board' => 'unos cuantos'], 'personsOnBoard'],
]);

it('descarta las letras del ítem 19 que no pertenecen a su grupo', function () {
    FlightPlanExtractorAgent::fake([[
        'emergency_radio' => ['V', 'E', 'X'],
        'jackets' => ['Q'],
    ]]);

    $result = app(FlightPlanExtractor::class)->extract('llevo VHF y ELT', emptyDraft());

    expect($result?->fields->emergencyRadio)->toBe(['V', 'E'])
        ->and($result->fields->jackets)->toBeNull();
});

/*
 * Aerodromes come back as the pilot's own words. A hallucinated SAZR sends
 * somebody to the wrong province, so the resolving is done against the real
 * register elsewhere.
 */
it('no acepta que el modelo resuelva un aeródromo', function () {
    FlightPlanExtractorAgent::fake([['departure_text' => 'el aeroclub de mi viejo']]);

    $result = app(FlightPlanExtractor::class)->extract('salgo del aeroclub de mi viejo', emptyDraft());

    expect($result?->departureText)->toBe('el aeroclub de mi viejo')
        ->and($result->fields->departureAerodrome)->toBeNull();
});

it('sigue funcionando cuando el proveedor falla', function () {
    FlightPlanExtractorAgent::fake(fn () => throw new RuntimeException('502'));

    expect(app(FlightPlanExtractor::class)->extract('plan LV-HAW', emptyDraft()))->toBeNull();
});

it('le pasa al modelo el borrador y lo que se preguntó', function () {
    FlightPlanExtractorAgent::fake([[]]);

    app(FlightPlanExtractor::class)->extract('3', emptyDraft(['aircraftIdentification' => 'LV-HAW']), ['personsOnBoard']);

    FlightPlanExtractorAgent::assertPrompted(function (AgentPrompt $prompt) {
        expect($prompt->prompt)->toContain('LV-HAW', 'personsOnBoard', 'UTC-3');

        return true;
    });
});
