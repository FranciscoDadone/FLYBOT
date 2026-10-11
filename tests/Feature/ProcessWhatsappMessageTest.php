<?php

use App\Contracts\WhatsappSender;
use App\DataObjects\ReplyContext;
use App\Jobs\ProcessWhatsappMessage;
use App\Models\WhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeWhatsappSender;

beforeEach(function () {
    Cache::flush();
    withoutAi();
    fakeAnac();

    $this->sender = new FakeWhatsappSender;
    $this->app->instance(WhatsappSender::class, $this->sender);
});

it('sends one message per notam to the original sender', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sent)->toHaveCount(3)
        ->and($this->sender->sent[0]['to'])->toBe('whatsapp:+5491111111111')
        ->and($this->sender->bodies()[0])->toContain('A2187/2026');
});

it('shows the typing indicator while the reply is being built', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'aeroparque', 'SM123'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->typing)->toBe(['SM123']);
});

it('skips the typing indicator when the inbound message id is unknown', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'aeroparque'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    // The ficha carries its menu, so it goes out as a list rather than as a
    // plain message. What matters here is that it went out at all.
    expect($this->sender->typing)->toBeEmpty()
        ->and($this->sender->sentWithList)->not->toBeEmpty();
});

/**
 * The dots are decoration; the answer is not.
 */
it('still replies when the typing indicator cannot be shown', function () {
    $sender = new class extends FakeWhatsappSender
    {
        public function indicateTyping(string $inboundMessageId): void
        {
            throw new RuntimeException('Twilio no disponible.');
        }
    };

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque', 'SM123'))
        ->handle(app(WhatsappBotService::class), $sender);

    expect($sender->sent)->toHaveCount(3);
});

/**
 * A failure while *building* the reply must still produce an answer — the
 * user is waiting in a chat and silence is the worst outcome.
 */
it('apologises instead of going silent when the reply cannot be built', function () {
    $bot = Mockery::mock(WhatsappBotService::class);
    $bot->shouldReceive('reply')->andThrow(new RuntimeException('boom'));

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'ezeiza'))
        ->handle($bot, $this->sender);

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->bodies()[0])->toContain('Tuve un problema');
});

/**
 * Delivery failures, by contrast, must propagate so the queue retries them.
 */
it('propagates a delivery failure so the queue can retry', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'aeroparque'))
        ->handle(app(WhatsappBotService::class), new FakeWhatsappSender(shouldFail: true));
})->throws(RuntimeException::class);

it('declares a retry policy', function () {
    $job = new ProcessWhatsappMessage('whatsapp:+5491111111111', 'eze');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
});

/**
 * The follow-up menu is a message of its own, sent after the answer — never
 * merged onto it, since WhatsApp renders one set of actions per message. It
 * goes out as a list sheet: there are more topics than the three a message can
 * draw as buttons.
 */
it('sends the menu as a list on a message of its own after the answer', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'notams aeroparque'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sent)->toHaveCount(3)
        ->and($this->sender->sentWithButtons)->toBeEmpty()
        ->and($this->sender->sentWithList)->toHaveCount(1)
        ->and($this->sender->sentWithList[0]['body'])->toContain('AEROPARQUE')
        ->and($this->sender->listRowIds())
        ->toBe(['ask:metar:SABE', 'ask:taf:SABE', 'ask:carta:SABE', 'ask:crepusculo:SABE', 'ask:info:SABE', 'ask:ubicacion:SABE']);
});

/**
 * Two messages, and two different shapes: the watch offer fits in buttons on
 * the report, the menu does not fit in three at all.
 */
it('sends the watch offer and the menu as two separate messages', function () {
    fakeMetar();

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'metar EZE'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->sentWithButtons)->toHaveCount(1)
        ->and(array_column($this->sender->sentWithButtons[0]['buttons'], 'id'))
        ->toBe(['sub:SAEZ:12'])
        ->and($this->sender->sentWithList)->toHaveCount(1)
        ->and($this->sender->listRowIds())->toContain('ask:carta:SAEZ');
});

/**
 * The one answer that hands over files. They lead — an attachment carries its
 * own caption, so it says what it is without a message introducing it — and the
 * text that follows is about the ones that were not sent.
 */
it('sends the charts first and offers the rest as a list', function () {
    fakeAipDocuments();

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'carta de aproximación de Santa Rosa'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->documents)->toHaveCount(2)
        ->and($this->sender->documents[0]['to'])->toBe('whatsapp:+5491111111111')
        ->and($this->sender->documents[0]['url'])->toBe('https://ais.anac.gob.ar/descarga/aip-test-osa-vor')
        ->and($this->sender->documents[0]['filename'])->toEndWith('.pdf')
        ->and($this->sender->sentWithList)->toHaveCount(1)
        ->and($this->sender->listRowIds())->toBe(['doc:SAZR:0', 'doc:SAZR:1'])
        ->and($this->sender->sentWithButtons)->toBeEmpty();
});

/**
 * The pin goes out as WhatsApp's own location message — nothing tappable can
 * ride on one, so the menu follows it as a message of its own.
 */
it('sends the location as a pin and the menu after it', function () {
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', '📍 Ubicación', null, 'ask:ubicacion:SABE'))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($this->sender->locations)->toHaveCount(1)
        ->and($this->sender->locations[0]['to'])->toBe('whatsapp:+5491111111111')
        ->and($this->sender->locations[0]['latitude'])->toEqualWithDelta(-34.5589, 0.0001)
        ->and($this->sender->locations[0]['name'])->toContain('AEROPARQUE')
        ->and($this->sender->sent)->toBeEmpty()
        ->and($this->sender->sentWithList)->toHaveCount(1)
        ->and($this->sender->listRowIds())->toContain('ask:info:SABE');
});

/**
 * Same reason as the captions below: a pin leaves no text behind it, and the
 * panel would show the row answered with nothing but the menu that followed.
 */
it('logs the pin it sent', function () {
    $log = WhatsappMessage::create([
        'phone' => 'whatsapp:+5491111111111',
        'body' => '📍 Ubicación',
    ]);

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', '📍 Ubicación', null, 'ask:ubicacion:SABE', $log))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($log->fresh()->reply[0])->toContain('AEROPARQUE')->toContain('-34.5588');
});

/**
 * A row that is only ever PDFs would otherwise sit in the panel answered and
 * empty, which is the one state that makes the log worth keeping.
 */
it('logs the captions of the files it sent', function () {
    fakeAipDocuments();

    $log = WhatsappMessage::create([
        'phone' => 'whatsapp:+5491111111111',
        'body' => 'carta de aproximación de Santa Rosa',
    ]);

    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'carta de aproximación de Santa Rosa', null, null, $log))
        ->handle(app(WhatsappBotService::class), $this->sender);

    expect($log->fresh()->reply[0])->toContain('VOR RWY 19');
});

function answeredConsultations(string $phone, int $count): void
{
    foreach (range(1, $count) as $i) {
        WhatsappMessage::create([
            'phone' => $phone,
            'body' => 'metar EZE',
            'anac_code' => 'EZE',
            'status' => WhatsappMessage::STATUS_ANSWERED,
        ]);
    }
}

function consultFrom(string $phone, string $body): WhatsappMessage
{
    $log = WhatsappMessage::create(['phone' => $phone, 'body' => $body]);

    (new ProcessWhatsappMessage($phone, $body, null, null, $log))
        ->handle(app(WhatsappBotService::class), test()->sender);

    return $log;
}

it('asks to be shared once the consultation count reaches the threshold', function () {
    config(['services.whatsapp.number' => '5491167620584']);
    answeredConsultations('whatsapp:+5491111111111', ProcessWhatsappMessage::SHARE_AFTER - 1);

    $log = consultFrom('whatsapp:+5491111111111', 'notams aeroparque');

    expect(last($this->sender->bodies()))->toContain('aeroclub')->toContain('https://wa.me/5491167620584')
        ->and(last($log->fresh()->reply))->toContain('aeroclub');
});

it('does not ask again after the threshold', function () {
    config(['services.whatsapp.number' => '5491167620584']);
    answeredConsultations('whatsapp:+5491111111111', ProcessWhatsappMessage::SHARE_AFTER);

    consultFrom('whatsapp:+5491111111111', 'notams aeroparque');

    expect(implode("\n", $this->sender->bodies()))->not->toContain('aeroclub');
});

it('does not ask before the threshold', function () {
    config(['services.whatsapp.number' => '5491167620584']);
    answeredConsultations('whatsapp:+5491111111111', ProcessWhatsappMessage::SHARE_AFTER - 2);

    consultFrom('whatsapp:+5491111111111', 'notams aeroparque');

    expect(implode("\n", $this->sender->bodies()))->not->toContain('aeroclub');
});

it('does not count other people\'s consultations', function () {
    config(['services.whatsapp.number' => '5491167620584']);
    answeredConsultations('whatsapp:+5492222222222', ProcessWhatsappMessage::SHARE_AFTER - 1);

    consultFrom('whatsapp:+5491111111111', 'notams aeroparque');

    expect(implode("\n", $this->sender->bodies()))->not->toContain('aeroclub');
});

it('does not ask after an apology', function () {
    config(['services.whatsapp.number' => '5491167620584']);
    answeredConsultations('whatsapp:+5491111111111', ProcessWhatsappMessage::SHARE_AFTER - 1);

    $bot = Mockery::mock(WhatsappBotService::class);
    $bot->shouldReceive('reply')->andThrow(new RuntimeException('boom'));
    $bot->shouldReceive('lastContext')->andReturn(new ReplyContext('notam', 'EZE'));

    $log = WhatsappMessage::create(['phone' => 'whatsapp:+5491111111111', 'body' => 'ezeiza']);
    (new ProcessWhatsappMessage('whatsapp:+5491111111111', 'ezeiza', null, null, $log))->handle($bot, $this->sender);

    expect($this->sender->bodies())->toHaveCount(1);
});

it('does not ask without a number to share', function () {
    config(['services.whatsapp.number' => null]);
    answeredConsultations('whatsapp:+5491111111111', ProcessWhatsappMessage::SHARE_AFTER - 1);

    consultFrom('whatsapp:+5491111111111', 'notams aeroparque');

    expect(implode("\n", $this->sender->bodies()))->not->toContain('aeroclub');
});
