<?php

use Illuminate\Support\Facades\Http;

/**
 * The deploy step for the form: nothing that changes in FlightPlanFlow reaches
 * a pilot until this has run, so what it sends is worth pinning.
 */
beforeEach(function () {
    config([
        'services.whatsapp.token' => 'token',
        'services.whatsapp.business_account_id' => '111',
        'services.whatsapp.graph_version' => 'v25.0',
        'fpl.flow.id' => null,
    ]);
});

it('imprime el documento sin hablar con Meta', function () {
    $this->artisan('fpl:flow --json')->assertSuccessful();

    // Http::preventStrayRequests() es global: si hubiera salido a la red, esto
    // habría fallado antes de llegar acá.
    expect(true)->toBeTrue();
});

it('crea el formulario sin publicarlo la primera vez', function () {
    Http::fake(['*/111/flows' => Http::response(['id' => '567'])]);

    $this->artisan('fpl:flow')
        ->expectsOutputToContain('WHATSAPP_FPL_FLOW_ID=567')
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://graph.facebook.com/v25.0/111/flows'
            && $body['categories'] === ['OTHER']
            // Sin id en el entorno no hay mensaje que lo referencie: publicarlo
            // acá dejaría un formulario vivo al que nadie apunta.
            && $body['publish'] === false
            && str_contains($body['flow_json'], 'AERONAVE');
    });
});

it('sube y publica una versión nueva sobre el mismo id', function () {
    config(['fpl.flow.id' => '567']);

    Http::fake([
        '*/567/assets' => Http::response(['success' => true]),
        '*/567/publish' => Http::response(['success' => true]),
    ]);

    $this->artisan('fpl:flow')->assertSuccessful();

    Http::assertSentCount(2);
});

/*
 * Meta contesta un documento inválido con la línea y la columna de cada queja.
 * Publicar igual dejaría a los pilotos con un formulario roto.
 */
it('no publica un documento que Meta rechazó', function () {
    config(['fpl.flow.id' => '567']);

    Http::fake([
        '*/567/assets' => Http::response([
            'validation_errors' => [[
                'error' => 'INVALID_PROPERTY_VALUE',
                'line_start' => 12,
                'message' => 'Unsupported version',
            ]],
        ]),
    ]);

    $this->artisan('fpl:flow')
        ->expectsOutputToContain('Unsupported version')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('avisa cuando faltan las credenciales de la cuenta', function () {
    config(['services.whatsapp.business_account_id' => null]);

    $this->artisan('fpl:flow')->assertFailed();
});
