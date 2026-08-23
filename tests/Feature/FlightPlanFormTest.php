<?php

use App\Contracts\WhatsappSender;
use App\DataObjects\ReplyFlow;
use App\Jobs\ProcessWhatsappMessage;
use App\Models\FlightPlanDraft;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeWhatsappSender;

const AVIADOR = 'whatsapp:+5491111111111';

/**
 * The plan filled in through the form inside WhatsApp rather than through the
 * chat.
 *
 * Which of the two the bot uses is one setting: with a Flow published at Meta
 * it hands over the whole form, and without one it asks its questions the way
 * it always did. Both paths stay -- the second is what runs wherever there is
 * no Meta to publish anything to, which is here, the local endpoint, and any
 * deployment that has not done the setup.
 */
beforeEach(function () {
    Cache::flush();
    withoutAi();

    config(['fpl.flow.id' => '1234567890']);

    $this->sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $this->sender);

    $this->bot = app(WhatsappBotService::class);
});

/**
 * A message, sent the way a real one arrives: through the job that delivers
 * the answer, so what the pilot receives is what gets asserted on.
 */
function say(string $message): void
{
    (new ProcessWhatsappMessage(AVIADOR, $message))->handle(test()->bot, test()->sender);
}

/**
 * The form the bot last sent, as it went out.
 *
 * @return array{to: string, body: string, flow: ReplyFlow}|array{}
 */
function lastForm(): array
{
    return end(test()->sender->sentWithFlow) ?: [];
}

/**
 * What a pilot would come back with, over whatever the form was opened with.
 *
 * @param  array<string, string>  $answers
 */
function submitForm(array $answers = []): void
{
    $sent = lastForm();

    (new ProcessWhatsappMessage(
        AVIADOR,
        '📝 Formulario del plan de vuelo',
        null,
        null,
        null,
        (string) json_encode([
            ...$sent['flow']->data,
            ...$answers,
            'flow_token' => $sent['flow']->token,
        ]),
    ))->handle(test()->bot, test()->sender);
}

it('manda el formulario en vez de preguntar casilla por casilla', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 2 a bordo');

    expect($this->sender->sentWithFlow)->toHaveCount(1)
        ->and($this->sender->sentWithList)->toBeEmpty();

    $form = lastForm()['flow'];

    expect($form->id)->toBe('1234567890')
        ->and($form->screen)->toBe('AERONAVE')
        // Lo que el mensaje ya trajo llega cargado: el piloto corrige, no dicta.
        ->and($form->data['aircraftIdentification'])->toBe('LV-HAW')
        ->and($form->data['departureAerodrome'])->toBe('SADF')
        ->and($form->data['personsOnBoard'])->toBe('2')
        ->and($form->data['typeOfAircraft'])->toBe('');
});

/*
 * El formulario es lo único tocable del mensaje: WhatsApp no dibuja botones
 * al lado de un Flow. La salida tiene que estar escrita, o el piloto queda
 * adentro del plan hasta que el borrador venza.
 */
it('dice cómo salir en el mensaje que lleva el formulario', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45');

    expect(lastForm()['body'])->toContain('cancelar');

    say('cancelar');

    expect(FlightPlanDraft::query()->where('phone', AVIADOR)->exists())->toBeFalse();
});

it('completa el plan con lo que volvió del formulario', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 2 a bordo');

    submitForm([
        'typeOfAircraft' => 'C208',
        'wakeTurbulenceCat' => 'L',
        'equipmentCom' => 'SDG',
        'equipmentSurveillance' => 'C',
        'flightRules' => 'I',
        'cruisingSpeed' => '175 kt',
        'level' => 'FL100',
        'route' => 'DCT',
        'aircraftColourAndMarkings' => 'blanco con azul',
        'pilotInCommand' => 'Nombre Apellido PCA123',
    ]);

    $fields = FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields();

    expect($fields->missing())->toBe([])
        ->and($fields->typeOfAircraft)->toBe('C208')
        ->and($fields->cruisingSpeed)->toBe('N0175')
        ->and($fields->destinationAerodrome)->toBe('SAZR');

    // Lleno el plan, lo que sigue es la tarjeta y el botón de generar.
    expect($this->sender->buttonIds())->toContain('fpl:ok');
});

/*
 * Item 13 is UTC and the box says "hora local". That the form asks the
 * question outright is the one thing it buys over a typed message, and it has
 * to be read that way on the way back.
 */
it('lee como local la hora que el formulario pidió en local', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa');

    submitForm(['departureTime' => '19:45', 'departureDate' => '2026-08-23']);

    expect(FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields()->departureTime)
        ->toBe('2245');
});

it('avisa qué aeródromo no pudo ubicar', function () {
    say('plan LV-HAW');

    submitForm(['departureAerodrome' => 'San Fernando', 'destinationAerodrome' => 'Qwertzuiop']);

    $said = implode("\n", [...$this->sender->bodies(), ...array_column($this->sender->sentWithFlow, 'body')]);

    expect($said)->toContain('Qwertzuiop')
        ->and(FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields()->departureAerodrome)
        ->toBe('SADF');
});

/*
 * The token is the whole of what ties a submission to a plan. A form filled in
 * after its draft is gone must not land on whatever draft came after it: that
 * is one flight's aircraft filed against another's route.
 */
it('no deja caer un formulario viejo sobre un plan nuevo', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45');

    $stale = lastForm();

    say('cancelar');
    say('plan LV-ABC de Rosario a Córdoba');

    (new ProcessWhatsappMessage(AVIADOR, '', null, null, null, (string) json_encode([
        ...$stale['flow']->data,
        'flow_token' => $stale['flow']->token,
        'typeOfAircraft' => 'C208',
    ])))->handle($this->bot, $this->sender);

    $fields = FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields();

    expect($fields->aircraftIdentification)->toBe('LV-ABC')
        ->and($fields->typeOfAircraft)->toBeNull()
        ->and(implode("\n", $this->sender->bodies()))->toContain('ya no está en pie');
});

it('abre el mismo formulario para corregir un plan que ya está lleno', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 2 a bordo');

    submitForm([
        'typeOfAircraft' => 'C208',
        'wakeTurbulenceCat' => 'L',
        'equipmentCom' => 'SDG',
        'equipmentSurveillance' => 'C',
        'flightRules' => 'I',
        'cruisingSpeed' => '175 kt',
        'level' => 'FL100',
        'route' => 'DCT',
        'aircraftColourAndMarkings' => 'blanco con azul',
        'pilotInCommand' => 'Nombre Apellido PCA123',
    ]);

    (new ProcessWhatsappMessage(AVIADOR, '', null, 'fpl:fix'))->handle($this->bot, $this->sender);

    expect(lastForm()['flow']->data['typeOfAircraft'])->toBe('C208')
        ->and(lastForm()['flow']->data['level'])->toBe('F100');
});

/*
 * Un casillero vacío es "no lo toqué", no "borralo": el formulario vuelve
 * entero cada vez, y leerlo al revés convertiría un componente que no abrió
 * cargado en un plan vaciado. La alternativa es la excepción, porque ahí sí
 * "ninguna" es una respuesta.
 */
it('no vacía el plan con los casilleros que volvieron vacíos', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 2 a bordo');

    $token = lastForm()['flow']->token;

    (new ProcessWhatsappMessage(AVIADOR, '', null, null, null, (string) json_encode([
        'flow_token' => $token,
        'typeOfAircraft' => 'C208',
    ])))->handle($this->bot, $this->sender);

    $fields = FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields();

    expect($fields->aircraftIdentification)->toBe('LV-HAW')
        ->and($fields->personsOnBoard)->toBe('2')
        ->and($fields->typeOfAircraft)->toBe('C208');
});

it('saca la alternativa cuando el piloto la borra del formulario', function () {
    say('plan LV-HAW de San Fernando a Santa Rosa alternativa Bahía Blanca');

    expect(lastForm()['flow']->data['alternateAerodrome'])->not->toBe('');

    submitForm(['alternateAerodrome' => '']);

    expect(FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields()->alternateAerodrome)
        ->toBeNull();
});

/*
 * Un formulario es lo único que el bot manda que puede fallar por algo del
 * lector y no de la red: un WhatsApp que no sabe dibujarlo. Sin red de
 * contención eso le cuesta al piloto la respuesta entera y se la vuelve a
 * costar en cada reintento, que es silencio.
 */
it('pregunta por escrito cuando el formulario no se puede entregar', function () {
    $this->sender->refusesFlows = true;

    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 2 a bordo');

    expect($this->sender->sentWithFlow)->toBeEmpty()
        ->and(implode("\n", $this->sender->buttonedBodies()))->toContain('tipo de aeronave')
        ->and($this->sender->buttonIds())->toContain('fpl:drop');
});

it('lee la respuesta escrita a la pregunta que reemplazó al formulario', function () {
    $this->sender->refusesFlows = true;

    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45');
    say('C208, L, SDG/C');

    $fields = FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields();

    expect($fields->typeOfAircraft)->toBe('C208')
        ->and($fields->wakeTurbulenceCat)->toBe('L')
        ->and($fields->equipmentCom)->toBe('SDG');
});

it('sigue preguntando en el chat donde no hay formulario publicado', function () {
    config(['fpl.flow.id' => null]);

    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 2 a bordo');

    expect($this->sender->sentWithFlow)->toBeEmpty()
        ->and($this->sender->buttonIds())->toContain('fpl:drop')
        ->and(implode("\n", $this->sender->buttonedBodies()))->toContain('tipo de aeronave');
});

it('llega desde el webhook como llega de Meta', function () {
    config(['services.whatsapp.app_secret' => 'secreto']);

    say('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45');

    postSigned(metaFlowPayload([
        'flow_token' => lastForm()['flow']->token,
        'typeOfAircraft' => 'C208',
    ]))->assertOk();

    expect(FlightPlanDraft::query()->where('phone', AVIADOR)->firstOrFail()->fields()->typeOfAircraft)
        ->toBe('C208');
});
