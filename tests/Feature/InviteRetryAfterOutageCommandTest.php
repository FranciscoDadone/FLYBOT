<?php

use App\Jobs\SendRetryInvitation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
});

function wrote(string $phone, string $at, string $body = 'metar sazr', string $status = WhatsappMessage::STATUS_ANSWERED): WhatsappMessage
{
    $message = WhatsappMessage::factory()->create(['phone' => $phone, 'body' => $body, 'status' => $status]);
    $message->forceFill(['created_at' => $at])->save();

    return $message;
}

function retryOutage(array $options = []): void
{
    test()->artisan('whatsapp:reintentar-caida', ['--desde' => '2026-10-11 00:00', '--hasta' => '2026-10-13 10:00', ...$options])
        ->assertSuccessful();
}

it('invites everyone who wrote during the outage, once per number', function () {
    wrote('whatsapp:+5491111111111', '2026-10-11 12:00', 'notam saez');
    $last = wrote('whatsapp:+5491111111111', '2026-10-12 08:00', 'metar saez');
    wrote('whatsapp:+5492222222222', '2026-10-12 09:00');

    retryOutage();

    Queue::assertPushed(SendRetryInvitation::class, 2);
    Queue::assertPushed(SendRetryInvitation::class, fn (SendRetryInvitation $job) => (new ReflectionProperty($job, 'original'))->getValue($job)->is($last));
});

it('leaves out whoever already got an answer after the outage', function () {
    wrote('whatsapp:+5491111111111', '2026-10-12 08:00');
    wrote('whatsapp:+5491111111111', '2026-10-13 11:00');

    retryOutage();

    Queue::assertNotPushed(SendRetryInvitation::class);
});

it('leaves out web chat sessions and messages outside the window', function () {
    wrote('web:6xppbio5co7s', '2026-10-12 08:00');
    wrote('whatsapp:+5491111111111', '2026-10-10 23:00');

    retryOutage();

    Queue::assertNotPushed(SendRetryInvitation::class);
});

it('sends nothing on a dry run', function () {
    wrote('whatsapp:+5491111111111', '2026-10-12 08:00');

    retryOutage(['--dry-run' => true]);

    Queue::assertNotPushed(SendRetryInvitation::class);
});

it('does not invite the same message twice when run again', function () {
    wrote('whatsapp:+5491111111111', '2026-10-12 08:00');

    retryOutage();
    retryOutage();

    Queue::assertPushed(SendRetryInvitation::class, 1);
});

it('refuses to run without a window', function () {
    $this->artisan('whatsapp:reintentar-caida')->assertFailed();
});
