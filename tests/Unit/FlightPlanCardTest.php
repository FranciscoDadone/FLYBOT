<?php

use App\Support\FlightPlanCard;

function card(): FlightPlanCard
{
    return app(FlightPlanCard::class);
}

/*
 * Boxes that are one thought are asked as one question. Three round trips for
 * "175 kt, FL100, DCT" is three round trips for a sentence the pilot would have
 * written in one go.
 */
it('pregunta junto lo que se contesta junto', function (array $missing, string $expected) {
    expect(card()->question($missing))->toContain($expected);
})->with([
    'la aeronave' => [['typeOfAircraft', 'wakeTurbulenceCat', 'route'], '*tipo de aeronave* y la *categoría de estela*'],
    'el crucero' => [['cruisingSpeed', 'level', 'route'], '*velocidad*, el *nivel* y la *ruta*'],
    'lo que va a bordo' => [['personsOnBoard', 'endurance'], '*personas a bordo* y la *autonomía*'],
]);

/*
 * A group with one member left is a plain question: asking for "el tipo de
 * aeronave y la categoría de estela" when only the estela is missing invites an
 * answer to a question nobody asked.
 */
it('pregunta de a uno cuando del grupo falta uno solo', function () {
    expect(card()->question(['wakeTurbulenceCat', 'route']))->toContain('*categoría de estela*')
        ->and(card()->question(['wakeTurbulenceCat', 'route']))->not->toContain('tipo de aeronave')
        ->and(card()->groupFor(['wakeTurbulenceCat', 'route']))->toBe([]);
});

it('descuenta el grupo entero de lo que queda por preguntar', function () {
    expect(card()->question(['cruisingSpeed', 'level', 'route', 'pilotInCommand']))
        ->toContain('Después me falta un dato más.');
});

it('cuenta el grupo en el orden en que lo nombra la pregunta', function () {
    expect(card()->groupFor(['level', 'route', 'cruisingSpeed']))->toBe(['cruisingSpeed', 'level', 'route']);
});

it('no tiene nada que preguntar cuando no falta nada', function () {
    expect(card()->question([]))->toBeNull()
        ->and(card()->groupFor([]))->toBe([]);
});
