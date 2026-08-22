<?php

use App\Contracts\WhatsappSender;
use App\Jobs\ProcessWhatsappMessage;
use App\Models\AircraftProfile;
use App\Models\FlightPlan;
use App\Models\FlightPlanDraft;
use App\Models\PilotProfile;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Support\FakeWhatsappSender;

const PILOT = 'whatsapp:+5491111111111';

beforeEach(function () {
    Cache::flush();
    withoutAi();

    $this->sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $this->sender);

    $this->bot = app(WhatsappBotService::class);
});

/**
 * The answers that fill in a first plan, in the order the bot asks for them.
 *
 * Five, for eighteen boxes: the questions that are one thought are asked as
 * one, and everything the aircraft profile will remember is asked once ever.
 *
 * @return array<int, string>
 */
function firstPlanAnswers(): array
{
    return ['C208, L, SDG/C', 'IFR', '175 kt, FL100, DCT', 'blanco con azul', 'Nombre Apellido PCA123'];
}

/**
 * A whole plan, filed the way a real one is: through the job that delivers it,
 * so what the pilot actually receives is what gets asserted on.
 */
function fileAPlan(WhatsappBotService $bot, string $opening = 'plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, FL100, 2 a bordo'): FlightPlan
{
    $bot->reply($opening, PILOT);

    foreach (firstPlanAnswers() as $answer) {
        $bot->reply($answer, PILOT);
    }

    (new ProcessWhatsappMessage(PILOT, '', null, 'fpl:ok'))
        ->handle($bot, test()->sender);

    return FlightPlan::query()->forPhone(PILOT)->mostRecentFirst()->firstOrFail();
}

it('abre un borrador con lo que el mensaje ya trae', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, FL100, 2 a bordo', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->aircraftIdentification)->toBe('LV-HAW')
        ->and($fields->departureAerodrome)->toBe('SADF')
        ->and($fields->destinationAerodrome)->toBe('SAZR')
        ->and($fields->level)->toBe('F100')
        ->and($fields->personsOnBoard)->toBe('2');
});

/*
 * Item 13 is UTC and a pilot says a local time. Three hours out is a plan filed
 * for the wrong slot, and it is the one error nothing downstream would catch.
 */
it('convierte a UTC la hora que el piloto dio en local', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    expect(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields()->departureTime)->toBe('2245');
});

it('no vuelve a correr la hora en cada mensaje siguiente', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    foreach (['C208', 'L', 'SDG', 'C'] as $answer) {
        $this->bot->reply($answer, PILOT);
    }

    expect(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields()->departureTime)->toBe('2245');
});

/*
 * "blanco con azul" answers a question about colours. Read through the general
 * matching it also moves the flight to Azul, which has an aerodrome — and a
 * pilot has no reason to re-read a destination they already gave.
 */
it('no deja que una respuesta cambie un aeródromo que ya estaba puesto', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, FL100, 2 a bordo', PILOT);

    foreach (['C208', 'L', 'SDG', 'C', 'IFR', '175 kt', 'DCT', 'blanco con azul'] as $answer) {
        $this->bot->reply($answer, PILOT);
    }

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->destinationAerodrome)->toBe('SAZR')
        ->and($fields->aircraftColourAndMarkings)->toBe('BLANCO CON AZUL');
});

it('entrega el PDF y dónde presentarlo', function () {
    $plan = fileAPlan($this->bot);

    expect($this->sender->documents)->toHaveCount(1);

    $document = $this->sender->documents[0];

    expect($document['url'])->toBe(route('fpl.pdf', ['token' => $plan->pdf_token]))
        ->and($document['filename'])->toEndWith('.pdf')
        ->and($document['filename'])->toContain('LV-HAW')
        ->and(implode("\n", $this->sender->buttonedBodies()))->toContain('acfdo@eana.com.ar');
});

/*
 * Filing the plan is an act with consequences, performed by the person who
 * signs it. A message that left that ambiguous would invite somebody to assume
 * the plan is already in.
 */
it('deja dicho que el plan lo presenta el piloto', function () {
    fileAPlan($this->bot);

    expect(implode("\n", $this->sender->buttonedBodies()))->toContain('el plan lo presentás vos');
});

it('escribe en el PDF lo que la conversación fue cargando', function () {
    $plan = fileAPlan($this->bot);

    $pdf = Storage::disk($plan->pdf_disk)->get((string) $plan->pdf_path);
    $text = (new Parser)->parseContent((string) $pdf)->getText();

    expect($text)->toContain('LV-HAW', 'SADF', '2245', 'SAZR', 'N0175', 'F100', 'BLANCO CON AZUL');
});

it('guarda lo que aprendió de la aeronave y del piloto', function () {
    fileAPlan($this->bot);

    $aircraft = AircraftProfile::query()->forPhone(PILOT)->firstOrFail();

    expect($aircraft->registration)->toBe('LV-HAW')
        ->and($aircraft->aircraft_type)->toBe('C208')
        ->and($aircraft->cruise_tas_kt)->toBe(175)
        ->and($aircraft->colour_markings)->toBe('BLANCO CON AZUL')
        ->and(PilotProfile::query()->where('phone', PILOT)->firstOrFail()->name)
        ->toBe('NOMBRE APELLIDO PCA123');
});

/*
 * The payoff: the second plan for the same aircraft is one message, because
 * everything that never changes is already known.
 */
it('deja el segundo plan listo de un solo mensaje', function () {
    fileAPlan($this->bot);
    $this->sender->documents = [];

    $reply = $this->bot->reply(
        'plan LV-HAW de San Fernando a Santa Rosa el viernes 08:00, 3 a bordo, IFR, DCT',
        PILOT,
    );

    expect(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields()->missing())->toBeEmpty()
        ->and(buttonIds($reply->button))->toContain('fpl:ok');
});

/*
 * An issued plan is kept a while so a late correction lands on it — but asking
 * for a plan is not a correction. Persons on board is what search and rescue is
 * given; it does not get to be inherited from a flight that already happened.
 */
it('no hereda personas a bordo del plan anterior', function () {
    fileAPlan($this->bot);

    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa el viernes 08:00', PILOT);

    expect(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields()->personsOnBoard)->toBeNull();
});

it('corrige un dato con un mensaje suelto y deja el resto intacto', function () {
    fileAPlan($this->bot);

    $this->bot->reply('', PILOT, 'fpl:fix');
    $this->bot->reply('3 a bordo', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->personsOnBoard)->toBe('3')
        ->and($fields->aircraftIdentification)->toBe('LV-HAW')
        ->and($fields->destinationAerodrome)->toBe('SAZR');
});

/*
 * A draft must not be a trap. Asking about the weather at the destination in
 * the middle of filling in a plan is exactly what a pilot does.
 */
it('deja preguntar otra cosa sin perder el borrador', function () {
    fakeMetar();

    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);
    $before = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->data;

    $reply = $this->bot->reply('metar SAZR', PILOT);

    expect(implode("\n", $reply->messages))->toContain('METAR')
        ->and(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->data)->toBe($before);
});

/*
 * The message a pilot writes into a plan is made of the same words as every
 * other question: an aerodrome and a day read on their own are a TAF. While the
 * form is open they are the plan -- this is the case that used to answer with
 * the forecast for Santa Rosa and throw the rest of the message away.
 */
it('lee para el plan un mensaje que suena a otro tema', function () {
    $this->bot->reply('plan de vuelo LV-HAW', PILOT);

    $reply = $this->bot->reply('de San Fernando a Santa Rosa pasado mañana, FL100, 3 a bordo', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->departureAerodrome)->toBe('SADF')
        ->and($fields->destinationAerodrome)->toBe('SAZR')
        ->and($fields->personsOnBoard)->toBe('3')
        ->and(implode("\n", $reply->messages))->not->toContain('TAF');
});

/*
 * The message is cut into pieces and each piece resolved on its own, so what is
 * left over after the route -- "color verde" -- is something a name lookup will
 * happily read as Isla Verde. An alternate has to be named to be filled.
 */
it('no toma por alternativa un pedazo suelto del mensaje', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45, 3 a bordo, color verde', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->alternateAerodrome)->toBeNull()
        ->and($fields->destinationAerodrome)->toBe('SAZR');
});

/*
 * Four boxes out of one answer. The alternative is four questions for a
 * sentence a pilot writes in one go -- and the slash is how item 10 is printed
 * on the form, so it is how it gets written back.
 */
it('llena varios casilleros con una sola respuesta', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    $this->bot->reply('C208, L, SDG/C', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->typeOfAircraft)->toBe('C208')
        ->and($fields->wakeTurbulenceCat)->toBe('L')
        ->and($fields->equipmentCom)->toBe('SDG')
        ->and($fields->equipmentSurveillance)->toBe('C');
});

it('lee la velocidad, el nivel y la ruta de una', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    foreach (['C208, L, SDG/C', 'IFR'] as $answer) {
        $this->bot->reply($answer, PILOT);
    }

    $this->bot->reply('175 kt, FL100, DCT', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->cruisingSpeed)->toBe('N0175')
        ->and($fields->level)->toBe('F100')
        ->and($fields->route)->toBe('DCT');
});

/*
 * The boxes that take anything at all -- the equipment, the route, the colour --
 * are not read out of a compound answer. Between two commas they would swallow
 * whatever nothing else claimed, and a colour of "no sé" prints as well as a
 * real one.
 */
it('no guarda cualquier cosa de una respuesta que no entendió', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    $reply = $this->bot->reply('ni idea, no sé', PILOT);

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->typeOfAircraft)->toBeNull()
        ->and($fields->equipmentCom)->toBeNull()
        ->and(implode("\n", $reply->messages))->toContain('No entendí');
});

it('llena el casillero con la opción que se tocó', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    $this->bot->reply('', PILOT, 'fpl:set:wakeTurbulenceCat:M');

    expect(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields()->wakeTurbulenceCat)->toBe('M');
});

/*
 * A tapped id is one the bot wrote, so it arrives well-formed -- but it ends up
 * on a document, and the list it has to come from is the one that was offered.
 */
it('no acepta una opción que no ofreció', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    $this->bot->reply('', PILOT, 'fpl:set:wakeTurbulenceCat:Q');

    expect(FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields()->wakeTurbulenceCat)->toBeNull();
});

it('descarta el borrador cuando se lo pide por escrito', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    $reply = $this->bot->reply('cancelar', PILOT);

    expect(FlightPlanDraft::query()->where('phone', PILOT)->exists())->toBeFalse()
        ->and(implode("\n", $reply->messages))->toContain('descarté');
});

/*
 * Once the plan is out the conversation is over. The draft is kept a few hours
 * so a late correction lands somewhere, but it stops taking messages that any
 * other topic recognised -- otherwise the pilot loses the bot for the rest of
 * the afternoon.
 */
it('deja de quedarse con todo una vez que mandó el PDF', function () {
    fakeTaf();

    fileAPlan($this->bot);

    $reply = $this->bot->reply('pronóstico de Santa Rosa para mañana', PILOT);

    expect(implode("\n", $reply->messages))->toContain('TAF');
});

it('descarta el borrador cuando se lo pide', function () {
    $this->bot->reply('plan LV-HAW de San Fernando a Santa Rosa mañana 19:45', PILOT);

    $this->bot->reply('', PILOT, 'fpl:drop');

    expect(FlightPlanDraft::query()->where('phone', PILOT)->exists())->toBeFalse();
});

/*
 * Reversing "DCT ASADA W68 IRASU" token by token produces something that reads
 * like a valid route and is not one: airways are directional. DCT is the one
 * case that does reverse.
 */
it('invierte el vuelo pero no una ruta con aerovías', function () {
    $plan = fileAPlan($this->bot);

    $this->bot->reply('', PILOT, "fpl:back:{$plan->id}");

    $fields = FlightPlanDraft::query()->where('phone', PILOT)->firstOrFail()->fields();

    expect($fields->departureAerodrome)->toBe('SAZR')
        ->and($fields->destinationAerodrome)->toBe('SADF')
        ->and($fields->route)->toBe('DCT');
});

it('no toca el plan de otro teléfono', function () {
    $plan = fileAPlan($this->bot);

    $this->bot->reply('', 'whatsapp:+5492222222222', "fpl:again:{$plan->id}");

    expect(FlightPlanDraft::query()->where('phone', 'whatsapp:+5492222222222')->exists())->toBeFalse();
});

it('ofrece los atajos a quien ya presentó un plan antes', function () {
    $plan = fileAPlan($this->bot);
    $this->bot->reply('', PILOT, 'fpl:drop');

    $reply = $this->bot->reply('plan de vuelo', PILOT);

    expect(buttonIds($reply->button))->toBe(["fpl:again:{$plan->id}", "fpl:back:{$plan->id}", 'fpl:new']);
});
