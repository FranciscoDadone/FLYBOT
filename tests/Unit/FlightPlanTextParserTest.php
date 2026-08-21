<?php

use App\DataObjects\FlightPlanFields;
use App\Support\FlightPlanTextParser;

function parser(): FlightPlanTextParser
{
    return app(FlightPlanTextParser::class);
}

it('lee la matrícula esté escrita como esté', function (string $written) {
    expect(parser()->registration("plan {$written} a santa rosa"))->toBe('LV-HAW');
})->with(['LV-HAW', 'LVHAW', 'lv-haw', 'lvhaw', 'LV HAW']);

it('no inventa una matrícula donde no la hay', function () {
    expect(parser()->registration('plan de vuelo a santa rosa mañana'))->toBeNull();
});

/*
 * Only an explicit mark counts as UTC. Reading a technical-looking message as
 * Zulu is how a plan ends up filed three hours out.
 */
it('sólo toma la hora como UTC si el mensaje lo dice', function (string $message, bool $utc) {
    expect(parser()->statesUtc($message))->toBe($utc);
})->with([
    'sin marca' => ['salgo 19:45', false],
    'con Z pegada' => ['salgo 1945Z', true],
    'con UTC' => ['salgo 19:45 UTC', true],
    'con zulu' => ['salgo a las 1945 zulu', true],
]);

it('lee la hora de salida', function (string $message, ?string $expected) {
    expect(parser()->departureTime($message))->toBe($expected);
})->with([
    'con dos puntos' => ['salgo 19:45', '1945'],
    'cuatro dígitos' => ['salgo 1945Z', '1945'],
    'con hs' => ['1945 hs', '1945'],
    'hora imposible' => ['2575', null],
]);

it('lee cómo se nombró el día', function (string $message, ?string $expected) {
    expect(parser()->dateText($message))->toBe($expected);
})->with([
    'mañana' => ['salgo mañana 19:45', 'manana'],
    'pasado' => ['pasado mañana', 'pasado manana'],
    'fecha' => ['el 12/7 a las 10', '12/7'],
    'nada' => ['salgo 19:45', null],
]);

it('lee un plan entero de un mensaje bien escrito', function () {
    $fields = parser()->parse('plan LV-HAW IFR, N0175 FL100, EET 0220, autonomía 5:00, 2 a bordo');

    expect($fields->aircraftIdentification)->toBe('LV-HAW')
        ->and($fields->flightRules)->toBe('I')
        ->and($fields->cruisingSpeed)->toBe('N0175')
        ->and($fields->level)->toBe('F100')
        ->and($fields->totalEet)->toBe('0220')
        ->and($fields->endurance)->toBe('0500')
        ->and($fields->personsOnBoard)->toBe('2');
});

it('acepta el nivel escrito de cualquiera de las formas', function (string $message, ?string $expected) {
    expect(parser()->parse($message)->level)->toBe($expected);
})->with([
    'FL100' => ['subo a FL100', 'F100'],
    'FL 100' => ['subo a FL 100', 'F100'],
    'F100' => ['nivel F100', 'F100'],
    'altitud' => ['nivel A045', 'A045'],
]);

it('convierte una velocidad en nudos al formato del formulario', function (string $message, ?string $expected) {
    expect(parser()->parse($message)->cruisingSpeed)->toBe($expected);
})->with([
    'del formulario' => ['N0175', 'N0175'],
    'en nudos' => ['crucero 175 kt', 'N0175'],
    'en nudos, palabra' => ['175 nudos', 'N0175'],
    'de dos cifras' => ['90 kt', 'N0090'],
]);

/*
 * Emptying a filled box needs an explicit phrase. Everything else leaves what
 * was there, because silence is not erasure.
 */
it('vacía la alternativa sólo cuando el mensaje lo dice', function () {
    expect(parser()->parse('sin alternativa')->alternateAerodrome)->toBe(FlightPlanFields::CLEAR)
        ->and(parser()->parse('voy a santa rosa')->alternateAerodrome)->toBeNull();
});

/*
 * A bare answer has no keywords to go on: what gives "3" its meaning is the
 * question it answers.
 */
it('lee una respuesta suelta contra el campo que se preguntó', function (string $field, string $answer, ?string $expected) {
    expect(parser()->parseAnswer($field, $answer))->toBe($expected);
})->with([
    'personas' => ['personsOnBoard', '3', '3'],
    'tripulación y pasaje' => ['personsOnBoard', '1/2', '1/2'],
    'EET en cuatro dígitos' => ['totalEet', '0220', '0220'],
    'EET con dos puntos' => ['totalEet', '2:20', '0220'],
    'autonomía en horas' => ['endurance', '5 horas', '0500'],
    'nivel suelto' => ['level', '100', 'F100'],
    'altitud en pies' => ['level', '4500 pies', 'A045'],
    'reglas' => ['flightRules', 'V', 'V'],
    'estela' => ['wakeTurbulenceCat', 'l', 'L'],
    'tipo de aeronave' => ['typeOfAircraft', 'c208', 'C208'],
    'color' => ['aircraftColourAndMarkings', 'blanco con azul', 'BLANCO CON AZUL'],
    'algo que no encaja' => ['personsOnBoard', 'un par', null],
    'estela inválida' => ['wakeTurbulenceCat', 'X', null],
]);

it('acepta una autonomía de más de un día, que un ferry sí tiene', function () {
    expect(parser()->parseAnswer('endurance', '30:00'))->toBe('3000');
});

/*
 * A route has no shape to match against -- it is an arbitrary run of airway
 * and fix names -- so what is matched is the anchor, and the run is taken
 * while it still looks like a route. The punctuation is where it ends.
 */
it('lee la ruta desde donde el mensaje la ancla', function (string $message, ?string $expected) {
    expect(parser()->route($message))->toBe($expected);
})->with([
    'nombrada' => ['ruta DCT ASADA W68 IRASU W73 TIMKU OSA', 'DCT ASADA W68 IRASU W73 TIMKU OSA'],
    'empezando por DCT' => ['DCT ASADA W68 IRASU, 2 a bordo', 'DCT ASADA W68 IRASU'],
    'sola en una frase' => ['plan LV-HAW a Santa Rosa, IFR, DCT, 3 a bordo', 'DCT'],
    'en castellano' => ['voy directo', 'DCT'],
    'sin ruta' => ['plan a santa rosa mañana', null],
]);

/*
 * "Santa Rosa de alternativa" names one aerodrome and means a different box
 * than "a Santa Rosa" does, and nothing about the name itself says which.
 */
it('distingue cuando un aeródromo se nombra como alternativa', function (string $message, bool $expected) {
    expect(parser()->namesAlternate($message))->toBe($expected);
})->with([
    'de alternativa' => ['santa rosa de alternativa', true],
    'abreviado' => ['ALTN SAZR', true],
    'alterno' => ['como alterno bahía blanca', true],
    'un destino común' => ['a santa rosa', false],
]);

/*
 * A grouped question invites a compound answer, and reading it as an answer to
 * the first box alone would throw the rest away. The order of the pieces is not
 * the pilot's problem: what reads them is the shape of each one.
 */
it('lee varios casilleros de una respuesta compuesta', function (array $group, array $missing, string $message, array $expected) {
    $fields = parser()->parseGroup($group, $missing, $message);

    foreach ($expected as $field => $value) {
        expect($fields->{$field})->toBe($value);
    }
})->with([
    'aeronave con equipo' => [
        ['typeOfAircraft', 'wakeTurbulenceCat'],
        ['typeOfAircraft', 'wakeTurbulenceCat', 'equipmentCom', 'equipmentSurveillance'],
        'C208, L, SDG/C',
        ['typeOfAircraft' => 'C208', 'wakeTurbulenceCat' => 'L', 'equipmentCom' => 'SDG', 'equipmentSurveillance' => 'C'],
    ],
    'aeronave sin equipo' => [
        ['typeOfAircraft', 'wakeTurbulenceCat'],
        ['typeOfAircraft', 'wakeTurbulenceCat'],
        'PA28 y M',
        ['typeOfAircraft' => 'PA28', 'wakeTurbulenceCat' => 'M'],
    ],
    'crucero' => [
        ['cruisingSpeed', 'level', 'route'],
        ['cruisingSpeed', 'level', 'route'],
        '175 kt, FL100, DCT',
        ['cruisingSpeed' => 'N0175', 'level' => 'F100', 'route' => 'DCT'],
    ],
    'crucero sin unidades' => [
        ['cruisingSpeed', 'level', 'route'],
        ['cruisingSpeed', 'level', 'route'],
        '175, 100, directo',
        ['cruisingSpeed' => 'N0175', 'level' => 'F100', 'route' => 'DCT'],
    ],
    'a bordo' => [
        ['personsOnBoard', 'endurance'],
        ['personsOnBoard', 'endurance'],
        '2 personas, autonomía 5:00',
        ['personsOnBoard' => '2', 'endurance' => '0500'],
    ],
    'a bordo pelado' => [
        ['personsOnBoard', 'endurance'],
        ['personsOnBoard', 'endurance'],
        '3, 4:30',
        ['personsOnBoard' => '3', 'endurance' => '0430'],
    ],
]);

/*
 * The boxes that take anything at all are not read out of a compound answer:
 * between two commas the first one still empty would swallow whatever nothing
 * else claimed. "1/2" is people, not equipment -- both halves of an item 10
 * pair have to start with a letter.
 */
it('no llena de cualquier cosa los casilleros sin forma', function (array $group, array $missing, string $message, array $empty) {
    $fields = parser()->parseGroup($group, $missing, $message);

    foreach ($empty as $field) {
        expect($fields->{$field})->toBeNull();
    }
})->with([
    'no sé' => [
        ['typeOfAircraft', 'wakeTurbulenceCat'],
        ['typeOfAircraft', 'wakeTurbulenceCat', 'equipmentCom', 'aircraftColourAndMarkings'],
        'ni idea, no sé',
        ['typeOfAircraft', 'wakeTurbulenceCat', 'equipmentCom', 'aircraftColourAndMarkings'],
    ],
    'personas escritas con barra' => [
        ['personsOnBoard', 'endurance'],
        ['personsOnBoard', 'endurance', 'equipmentCom', 'equipmentSurveillance'],
        '1/2',
        ['equipmentCom', 'equipmentSurveillance'],
    ],
]);

/*
 * A compound answer fills empty boxes. It never changes one that is already
 * right: what is not on the missing list is not on offer.
 */
it('no toca un casillero que ya estaba lleno', function () {
    $fields = parser()->parseGroup(['level'], ['level'], '175 kt, FL100, DCT');

    expect($fields->level)->toBe('F100')
        ->and($fields->cruisingSpeed)->toBeNull()
        ->and($fields->route)->toBeNull();
});
