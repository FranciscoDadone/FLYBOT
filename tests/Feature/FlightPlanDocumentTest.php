<?php

use App\DataObjects\FlightPlanFields;
use App\Models\FlightPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

function storedPlan(array $overrides = []): FlightPlan
{
    $token = FlightPlan::newToken();
    $path = config('fpl.directory')."/{$token}.pdf";

    Storage::disk('local')->put($path, '%PDF-1.4 fake');

    return FlightPlan::create([
        'phone' => 'whatsapp:+5491111111111',
        'data' => (new FlightPlanFields(aircraftIdentification: 'LV-HAW', departureAerodrome: 'SADF'))->toStorage(),
        'pdf_disk' => 'local',
        'pdf_path' => $path,
        'pdf_token' => $token,
        'pdf_expires_at' => CarbonImmutable::now()->addDay(),
        ...$overrides,
    ]);
}

it('entrega el PDF a quien tiene el enlace', function () {
    $plan = storedPlan();

    $response = $this->get(route('fpl.pdf', ['token' => $plan->pdf_token]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF');
});

/*
 * One 404 for every way of not being allowed to have it, so the response says
 * nothing about which.
 */
it('devuelve 404 para cualquier enlace que no sirva', function (Closure $token) {
    $this->get(route('fpl.pdf', ['token' => $token()]))->assertNotFound();
})->with([
    'vencido' => [fn () => storedPlan(['pdf_expires_at' => CarbonImmutable::now()->subHour()])->pdf_token],
    'ya podado' => [fn () => storedPlan(['pdf_path' => null])->pdf_token],
    'inexistente' => [fn () => str_repeat('z', 40)],
]);

it('no acepta un token con otra forma', function (string $token) {
    $this->get("/fpl/{$token}.pdf")->assertNotFound();
})->with([
    'corto' => ['abc'],
    'con caracteres raros' => [str_repeat('a', 39).'/'],
]);

it('le pone al archivo un nombre que se lee en el teléfono', function () {
    $plan = storedPlan();

    expect($plan->filename())->toBe('LV-HAW-SADF.pdf');
});

it('borra los documentos vencidos y deja el plan para repetirlo', function () {
    $vigente = storedPlan();
    $vencido = storedPlan(['pdf_expires_at' => CarbonImmutable::now()->subHour()]);

    $this->artisan('fpl:prune')->assertSuccessful();

    expect($vencido->fresh()->pdf_path)->toBeNull()
        ->and(Storage::disk('local')->exists((string) $vigente->pdf_path))->toBeTrue()
        ->and($vencido->fresh())->not->toBeNull();
});
