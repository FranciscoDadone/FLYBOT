<?php

use App\DataObjects\FlightPlanFields;
use App\Services\FlightPlanPdfRenderer;
use Smalot\PdfParser\Parser;

/**
 * The plan the form's coordinates were measured from, so what these tests
 * assert against is a page that exists on paper.
 */
function sampleFlightPlan(mixed ...$overrides): FlightPlanFields
{
    return new FlightPlanFields(...[
        'aircraftIdentification' => 'LVHAW',
        'flightRules' => 'I',
        'typeOfFlight' => 'G',
        'typeOfAircraft' => 'C208',
        'wakeTurbulenceCat' => 'L',
        'equipmentCom' => 'SDG',
        'equipmentSurveillance' => 'C',
        'departureAerodrome' => 'SADF',
        'departureTime' => '1945',
        'cruisingSpeed' => 'N0175',
        'level' => 'F100',
        'route' => 'DCT ASADA W68 IRASU W73 TIMKU OSA',
        'destinationAerodrome' => 'SAZR',
        'totalEet' => '0220',
        'alternateAerodrome' => 'SAZR',
        'otherInformation' => 'DOF/260704 OPR/JAVIERCATONI PER/B',
        'endurance' => '0500',
        'personsOnBoard' => '1/2',
        'emergencyRadio' => ['V', 'E'],
        'aircraftColourAndMarkings' => 'BLANCO CON AZUL',
        'pilotInCommand' => 'NOMBRE APELLIDO PCA123',
        'filedBy' => 'NOMBRE APELLIDO PCA123',
        ...$overrides,
    ]);
}

function renderedText(FlightPlanFields $fields): string
{
    $pdf = app(FlightPlanPdfRenderer::class)->render($fields);

    return (new Parser)->parseContent($pdf)->getText();
}

it('produce un PDF de una sola página', function () {
    $pdf = app(FlightPlanPdfRenderer::class)->render(sampleFlightPlan());

    expect($pdf)->toStartWith('%PDF')
        ->and((new Parser)->parseContent($pdf)->getPages())->toHaveCount(1);
});

/*
 * The point of this one: the renderer draws text at absolute positions, so a
 * field that stops being written produces a page that still looks like a form
 * and is missing the thing a pilot needs. Reading the text back out is the
 * cheapest guard against that, and it is end-to-end — template, layout table
 * and DTO all have to agree for a value to appear here.
 */
it('escribe cada ítem del plan en el PDF', function (string $expected) {
    expect(renderedText(sampleFlightPlan()))->toContain($expected);
})->with([
    'ítem 7 matrícula' => 'LVHAW',
    'ítem 9 tipo' => 'C208',
    'ítem 10 equipo' => 'SDG',
    'ítem 13 salida' => 'SADF',
    'ítem 13 hora' => '1945',
    'ítem 15 velocidad' => 'N0175',
    'ítem 15 nivel' => 'F100',
    'ítem 15 ruta' => 'DCT ASADA W68 IRASU W73 TIMKU OSA',
    'ítem 16 destino' => 'SAZR',
    'ítem 16 EET' => '0220',
    'ítem 18 otros datos' => 'DOF/260704 OPR/JAVIERCATONI PER/B',
    'ítem 19 autonomía' => '0500',
    'ítem 19 color' => 'BLANCO CON AZUL',
    'ítem 19 comandante' => 'NOMBRE APELLIDO PCA123',
]);

it('pasa todo a mayúsculas, que es como se completa el formulario', function () {
    $text = renderedText(sampleFlightPlan(
        aircraftColourAndMarkings: 'blanco con azul',
        pilotInCommand: 'nombre apellido',
    ));

    expect($text)->toContain('BLANCO CON AZUL', 'NOMBRE APELLIDO')
        ->not->toContain('blanco con azul');
});

it('omite los campos vacíos en vez de escribir una cadena vacía', function () {
    $text = renderedText(new FlightPlanFields(aircraftIdentification: 'LVHAW'));

    expect($text)->toContain('LVHAW')->not->toContain('SADF');
});

/*
 * Nothing at runtime ties the layout table to the object it draws: a renamed
 * property leaves a coordinate pointing at nothing and the field silently
 * stops being printed. This is what notices.
 */
it('mantiene la tabla de coordenadas atada al plan y a la hoja', function () {
    $layout = require resource_path('data/fpl-layout.php');
    $fields = new FlightPlanFields;

    foreach ([...array_keys($layout['fields']), ...array_keys($layout['combs'])] as $name) {
        expect(property_exists($fields, $name))->toBeTrue("El layout ubica '{$name}', que no es un campo del plan.");
    }

    foreach ($layout['fields'] as $name => $box) {
        expect($box['x'] + $box['w'])->toBeLessThanOrEqual($layout['page']['width'], "'{$name}' se sale de la hoja.")
            ->and($box['y'])->toBeLessThan($layout['page']['height'], "'{$name}' cae debajo de la hoja.");
    }

    foreach ($layout['strikes'] as $group => $spec) {
        foreach ($spec['cells'] as $letter => $x) {
            expect($x + $spec['w'])->toBeLessThanOrEqual($layout['page']['width'], "El tachado {$group}/{$letter} se sale de la hoja.");
        }
    }
});

it('avisa cuando un valor no entra en su casilla en vez de recortarlo callado', function () {
    $renderer = app(FlightPlanPdfRenderer::class);

    $renderer->render(sampleFlightPlan());
    expect($renderer->overflowed())->toBeEmpty();

    $renderer->render(sampleFlightPlan(route: str_repeat('SIRTA W44 EPOKA ', 40)));
    expect($renderer->overflowed())->toContain('route');
});

/*
 * Item 19 preprints its letters and the pilot strikes out what the aircraft
 * does NOT carry, so the mark is the negative of the value. Read the other way
 * round it claims equipment that is not aboard, which is exactly the mistake a
 * search would be run on.
 */
it('tacha el equipo que NO se lleva, no el que se lleva', function () {
    $renderer = app(FlightPlanPdfRenderer::class);

    $struck = fn (FlightPlanFields $f) => count($renderer->strikeBoxes($f));

    // Radio: tres casillas, U V E. Llevando VHF y ELT se tacha sólo UHF.
    expect($struck(new FlightPlanFields(emergencyRadio: ['V', 'E'])))
        ->toBeLessThan($struck(new FlightPlanFields(emergencyRadio: [])));

    // Chalecos: llevando los cinco no se tacha ninguno de ese grupo.
    $all = $struck(new FlightPlanFields(jackets: ['J', 'L', 'F', 'U', 'V']));
    expect($struck(new FlightPlanFields))->toBe($all + 5);
});

it('no tacha la cubierta de un bote que no existe', function () {
    $renderer = app(FlightPlanPdfRenderer::class);

    $cells = fn (FlightPlanFields $f) => array_map(
        fn (array $box) => $box['left'].':'.$box['top'],
        $renderer->strikeBoxes($f),
    );

    $layout = require resource_path('data/fpl-layout.php');
    $mm = 210.0 / $layout['page']['width'];
    $cover = round($layout['strikes']['dinghies']['cells']['C'] * $mm, 2);

    // Sin botes a bordo la fila entera está de más: la D lo dice, y tachar
    // además la cubierta sería afirmar algo de un bote que no está.
    $none = $cells(new FlightPlanFields);
    expect($none)->not->toContain($cover.':'.round($layout['strikes']['dinghies']['y'] * (297.0 / $layout['page']['height']), 2));

    // Con botes sin cubierta, la cubierta sí se tacha y la D no.
    $uncovered = $cells(new FlightPlanFields(dinghiesNumber: '2', dinghiesCovered: false));
    expect($uncovered)->toContain($cover.':'.round($layout['strikes']['dinghies']['y'] * (297.0 / $layout['page']['height']), 2));
});
