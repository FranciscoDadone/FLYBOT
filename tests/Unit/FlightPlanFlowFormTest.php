<?php

use App\DataObjects\FlightPlanFields;
use App\DataObjects\ReplyButton;
use App\Support\FlightPlanFlow;
use Carbon\CarbonImmutable;

/**
 * The form the bot sends into WhatsApp, checked here rather than at Meta.
 *
 * Nothing else can check it. The document is published once and read by a
 * phone, so a box that stops being handed from one screen to the next is not
 * an error anywhere -- it is a value that silently fails to arrive, and the
 * pilot is asked for something they already answered.
 */
function flowForm(): FlightPlanFlow
{
    return app(FlightPlanFlow::class);
}

/**
 * Every `${data.x}` written anywhere inside one screen.
 *
 * @param  array<string, mixed>  $screen
 * @return array<int, string>
 */
function dataReferences(array $screen): array
{
    preg_match_all('/\$\{data\.([a-zA-Z]+)\}/', (string) json_encode($screen), $m);

    return array_values(array_unique($m[1]));
}

it('lleva todos los casilleros de una pantalla a la siguiente', function () {
    $screens = flowForm()->document()['screens'];

    $boxes = null;

    foreach ($screens as $screen) {
        $footer = collect($screen['layout']['children'][0]['children'])->firstWhere('type', 'Footer');
        $payload = array_keys($footer['on-click-action']['payload']);

        // Bar the bounds on the date picker, which stop being carried once the
        // screen that uses them has gone by.
        $carried = array_values(array_diff($payload, ['minDate', 'maxDate']));

        $boxes ??= $carried;

        test()->assertSame($boxes, $carried, "{$screen['id']} no pasa lo mismo que la pantalla anterior");
    }

    // What arrives is a whole flight plan and not the last screen of one.
    expect($boxes)->toContain('aircraftIdentification', 'route', 'pilotInCommand');
});

/*
 * A screen can only read data it declared, and can only read from its own form
 * the boxes it drew. Either one wrong is a form that opens empty or a value
 * that never arrives -- and neither says so.
 */
it('sólo lee datos que la pantalla declaró', function () {
    foreach (flowForm()->document()['screens'] as $screen) {
        $declared = array_keys($screen['data']);

        foreach (dataReferences($screen) as $reference) {
            test()->assertContains($reference, $declared, "{$screen['id']} lee {$reference} sin declararlo");
        }
    }
});

it('sólo toma del formulario los casilleros que dibujó', function () {
    foreach (flowForm()->document()['screens'] as $screen) {
        $children = $screen['layout']['children'][0]['children'];
        $drawn = array_values(array_filter(array_column($children, 'name')));

        $footer = collect($children)->firstWhere('type', 'Footer');

        preg_match_all('/\$\{form\.([a-zA-Z]+)\}/', (string) json_encode($footer), $m);

        foreach (array_unique($m[1]) as $reference) {
            test()->assertContains($reference, $drawn, "{$screen['id']} toma {$reference} sin dibujarlo");
        }
    }
});

it('termina en una pantalla que cierra el formulario', function () {
    $screens = flowForm()->document()['screens'];

    $terminal = array_values(array_filter($screens, fn (array $s) => ($s['terminal'] ?? false) === true));

    expect($terminal)->toHaveCount(1)
        ->and($terminal[0]['id'])->toBe(end($screens)['id']);

    $footer = collect($terminal[0]['layout']['children'][0]['children'])->firstWhere('type', 'Footer');

    expect($footer['on-click-action']['name'])->toBe('complete');
});

it('abre en la pantalla que el mensaje dice abrir', function () {
    expect(flowForm()->document()['screens'][0]['id'])->toBe(FlightPlanFlow::FIRST_SCREEN);
});

/*
 * Meta cuts a label at twenty characters and rejects the document past it. The
 * rejection comes when the form is published, which is long after the line was
 * written and nowhere near it.
 */
it('se queda dentro de lo que WhatsApp dibuja', function () {
    foreach (flowForm()->document()['screens'] as $screen) {
        foreach ($screen['layout']['children'][0]['children'] as $child) {
            if (isset($child['label']) && $child['type'] !== 'Footer') {
                expect(mb_strlen($child['label']))->toBeLessThanOrEqual(FlightPlanFlow::MAX_LABEL);
            }

            if (isset($child['helper-text'])) {
                expect(mb_strlen($child['helper-text']))->toBeLessThanOrEqual(FlightPlanFlow::MAX_HELPER);
            }

            foreach ($child['data-source'] ?? [] as $option) {
                expect(mb_strlen($option['title']))->toBeLessThanOrEqual(FlightPlanFlow::MAX_OPTION_TITLE);
            }
        }
    }
});

/*
 * The dropdowns and the chat's list sheets offer the same letters because they
 * read the same table. Two tables would be two answers to "what does S mean in
 * item 10", and the pilot would see whichever one the bot happened to use.
 */
it('ofrece las mismas letras que las hojas del chat', function () {
    $screen = flowForm()->document()['screens'][0];

    $estela = collect($screen['layout']['children'][0]['children'])->firstWhere('name', 'wakeTurbulenceCat');

    expect(array_column($estela['data-source'], 'id'))
        ->toBe(array_column(ReplyButton::flightPlanOptions('wakeTurbulenceCat'), 0));
});

it('deja escribir un equipo que la lista no cubre', function () {
    $screen = flowForm()->document()['screens'][0];
    $children = $screen['layout']['children'][0]['children'];

    $equipo = collect($children)->firstWhere('name', 'equipmentCom');

    expect(array_column($equipo['data-source'], 'id'))->toContain('OTRO')
        ->and(collect($children)->firstWhere('name', 'equipmentOther'))->not->toBeNull();
});

/*
 * Lo que abre los casilleros cargados cuelga del formulario y no de cada
 * casillero: Meta rechaza el documento entero si se lo pone en el componente.
 * Puesto en el lugar equivocado no hay error en ninguna parte -- el formulario
 * simplemente abre vacío, y el piloto vuelve a dictar lo que el bot ya sabía.
 */
it('cuelga los valores iniciales del formulario y no de cada casillero', function () {
    foreach (flowForm()->document()['screens'] as $screen) {
        $form = $screen['layout']['children'][0];

        expect($form['type'])->toBe('Form')
            ->and($form['init-values'])->not->toBeEmpty();

        foreach ($form['children'] as $child) {
            expect($child)->not->toHaveKey('init-value');

            if (isset($child['name']) && $child['type'] !== 'Footer') {
                expect($form['init-values'])->toHaveKey($child['name']);
            }
        }
    }
});

it('trae cargado todo lo que la pantalla sabe abrir', function () {
    $prefill = flowForm()->prefill(new FlightPlanFields(aircraftIdentification: 'LV-HAW'));

    foreach (flowForm()->document()['screens'] as $screen) {
        foreach (array_keys($screen['data']) as $key) {
            expect($prefill)->toHaveKey($key);
        }
    }

    expect($prefill['aircraftIdentification'])->toBe('LV-HAW')
        ->and($prefill['route'])->toBe('');
});

/*
 * What is stored is UTC, because item 13 is. Putting that back into a box
 * labelled "hora local" is how a plan walks three hours forward on every pass.
 */
it('devuelve al formulario la hora en local', function () {
    $prefill = flowForm()->prefill(new FlightPlanFields(
        departureTime: '2245',
        departureAt: CarbonImmutable::parse('2026-08-23 22:45', 'UTC'),
    ));

    expect($prefill['departureTime'])->toBe('19:45')
        ->and($prefill['departureDate'])->toBe('2026-08-23');
});

it('abre el equipo en Otro cuando no es uno de la lista', function () {
    $prefill = flowForm()->prefill(new FlightPlanFields(
        equipmentCom: 'SDFGRY',
        equipmentSurveillance: 'S',
    ));

    expect($prefill['equipmentCom'])->toBe('OTRO')
        ->and($prefill['equipmentOther'])->toBe('SDFGRY/S');
});

it('lee lo que el piloto completó', function () {
    $fields = flowForm()->read([
        'flow_token' => 'x',
        'aircraftIdentification' => 'lv-haw',
        'typeOfAircraft' => 'c208',
        'wakeTurbulenceCat' => 'L',
        'flightRules' => 'I',
        'cruisingSpeed' => '175 kt',
        'level' => 'FL100',
        'personsOnBoard' => '2',
        'endurance' => '5:00',
    ]);

    expect($fields->aircraftIdentification)->toBe('LV-HAW')
        ->and($fields->typeOfAircraft)->toBe('C208')
        ->and($fields->wakeTurbulenceCat)->toBe('L')
        ->and($fields->flightRules)->toBe('I')
        ->and($fields->cruisingSpeed)->toBe('N0175')
        ->and($fields->level)->toBe('F100')
        ->and($fields->personsOnBoard)->toBe('2')
        ->and($fields->endurance)->toBe('0500');
});

it('no guarda una opción que el formulario no ofreció', function () {
    expect(flowForm()->read(['wakeTurbulenceCat' => 'Q'])->wakeTurbulenceCat)->toBeNull();
});

it('deja vacío lo que volvió vacío', function () {
    expect(flowForm()->read(['route' => '', 'level' => '   '])->toStorage())->toBe([]);
});

it('deja el equipo escrito por encima del que se eligió', function () {
    $fields = flowForm()->read([
        'equipmentCom' => 'SDG',
        'equipmentSurveillance' => 'C',
        'equipmentOther' => 'sdfgry/s',
    ]);

    expect($fields->equipmentCom)->toBe('SDFGRY')
        ->and($fields->equipmentSurveillance)->toBe('S');
});

it('no lee los aeródromos, que se resuelven contra la base', function () {
    expect(flowForm()->read(['departureAerodrome' => 'San Fernando'])->departureAerodrome)->toBeNull()
        ->and(flowForm()->aerodromes(['departureAerodrome' => 'San Fernando', 'destinationAerodrome' => '']))
        ->toBe(['departureAerodrome' => 'San Fernando']);
});

it('lee la fecha de las dos formas en que el selector la manda', function (string $raw) {
    CarbonImmutable::setTestNow('2026-08-22 12:00:00');

    expect(flowForm()->departureDate(['departureDate' => $raw]))->toBe('23/08/2026');

    CarbonImmutable::setTestNow();
})->with([
    'calendario' => '2026-08-23',
    'milisegundos' => '1787443200000',
]);

/*
 * Seconds where milliseconds were expected resolves to 1970, and a plan filed
 * for 1970 is not something to find out about from the PDF.
 */
it('no toma por fecha algo que cae fuera de lo que el selector permitía', function (string $raw) {
    CarbonImmutable::setTestNow('2026-08-22 12:00:00');

    expect(flowForm()->departureDate(['departureDate' => $raw]))->toBeNull();

    CarbonImmutable::setTestNow();
})->with([
    'segundos en vez de milisegundos' => '1787443200',
    'el año que viene' => '2027-08-23',
    'ayer' => '2026-08-20',
    'cualquier cosa' => 'mañana',
]);

it('no se cae con un formulario que volvió roto', function () {
    expect(flowForm()->decode('{no json'))->toBe([])
        ->and(flowForm()->decode('[]'))->toBe([])
        ->and(flowForm()->token([]))->toBe('');
});
