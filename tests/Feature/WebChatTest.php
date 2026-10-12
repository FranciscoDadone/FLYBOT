<?php

use App\DataObjects\ReplyContext;
use App\Models\MetarSubscription;
use App\Models\WhatsappMessage;
use App\Services\WhatsappBotService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    withoutAi();
});

it('answers with the same messages WhatsApp would get', function () {
    fakeMetar();

    $response = $this->postJson('/chat', ['mensaje' => 'metar EZE'])->assertOk();

    $bubbles = $response->json('mensajes');

    expect($bubbles)->not->toBeEmpty()
        ->and(collect($bubbles)->pluck('texto')->implode("\n"))->toContain('SAEZ')
        ->and(collect($bubbles)->pluck('lista')->filter())->not->toBeEmpty();
});

/**
 * The page has nowhere to write to once it is closed, so the watch is not
 * offered — and asking for one, or tapping a stale button, says why.
 */
it('never offers or creates an alert', function () {
    fakeMetar();

    $bubbles = $this->postJson('/chat', ['mensaje' => 'metar EZE'])->json('mensajes');

    expect(collect($bubbles)->pluck('botones')->flatten(1)->pluck('id'))->not->toContain('sub:SAEZ:12');

    $this->postJson('/chat', ['mensaje' => 'avisame EZE'])
        ->assertOk()
        ->assertJsonPath('mensajes.0.texto', fn (string $text) => str_contains($text, 'sólo funcionan por WhatsApp'));

    $this->postJson('/chat', ['mensaje' => 'Avisarme 12 h', 'boton' => 'sub:SAEZ:12'])
        ->assertJsonPath('mensajes.0.texto', fn (string $text) => str_contains($text, 'sólo funcionan por WhatsApp'));

    expect(MetarSubscription::count())->toBe(0);
});

it('acts on a tapped row the way the webhook does', function () {
    fakeTaf();

    $bubbles = $this->postJson('/chat', ['mensaje' => '🔭 TAF', 'boton' => 'ask:taf:SAEZ'])->json('mensajes');

    expect(collect($bubbles)->pluck('texto')->implode("\n"))->toContain('TAF');
});

it('logs the conversation under one web id per session', function () {
    fakeMetar();

    $this->postJson('/chat', ['mensaje' => 'metar EZE'])->assertOk();
    $this->postJson('/chat', ['mensaje' => 'metar EZE'])->assertOk();

    $messages = WhatsappMessage::all();

    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('phone')->unique())->toHaveCount(1)
        ->and($messages[0]->phone)->toStartWith('web:')
        ->and($messages[0]->status)->toBe(WhatsappMessage::STATUS_ANSWERED)
        ->and($messages[0]->anac_code)->toBe('EZE')
        ->and($messages[0]->reply)->not->toBeEmpty();
});

it('apologises and marks the row failed when the reply cannot be built', function () {
    $bot = Mockery::mock(WhatsappBotService::class);
    $bot->shouldReceive('reply')->andThrow(new RuntimeException('ANAC caída'));
    $bot->shouldReceive('lastContext')->andReturn(new ReplyContext);
    app()->instance(WhatsappBotService::class, $bot);

    $this->postJson('/chat', ['mensaje' => 'notams EZE'])
        ->assertOk()
        ->assertJsonPath('mensajes.0.texto', fn (string $text) => str_contains($text, 'Tuve un problema'));

    expect(WhatsappMessage::sole()->status)->toBe(WhatsappMessage::STATUS_FAILED);
});

it('rejects an empty message', function () {
    $this->postJson('/chat', ['mensaje' => '  '])->assertUnprocessable();

    expect(WhatsappMessage::count())->toBe(0);
});
