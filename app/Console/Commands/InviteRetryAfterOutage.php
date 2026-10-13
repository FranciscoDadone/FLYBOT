<?php

namespace App\Console\Commands;

use App\Jobs\SendRetryInvitation;
use App\Models\WhatsappMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * After an outage, offers everyone who wrote during it their last question
 * again.
 *
 * The window has to be given by hand: a message sent before the delivery
 * receipts were tracked reads as answered even if it never arrived, so status
 * alone cannot tell who was left without a reply.
 */
class InviteRetryAfterOutage extends Command
{
    protected $signature = 'whatsapp:reintentar-caida
        {--desde= : Comienzo de la caída (hora del servidor)}
        {--hasta= : Momento en que el bot volvió a responder}
        {--dry-run : Listar a quién se le escribiría, sin enviar nada}';

    protected $description = 'Send the "Reintentar" template to everyone who wrote to the bot during an outage.';

    public function handle(): int
    {
        if (blank($this->option('desde')) || blank($this->option('hasta'))) {
            $this->error('Faltan --desde y --hasta.');

            return self::INVALID;
        }

        $from = Carbon::parse((string) $this->option('desde'));
        $until = Carbon::parse((string) $this->option('hasta'));

        $cameBack = WhatsappMessage::query()
            ->since($until)
            ->answered()
            ->distinct()
            ->pluck('phone');

        // Only WhatsApp: a web chat session has no number to write to.
        $originals = WhatsappMessage::query()
            ->since($from)
            ->where('created_at', '<', $until)
            ->where('phone', 'like', 'whatsapp:%')
            ->whereNotIn('phone', $cameBack)
            ->orderBy('id')
            ->get()
            ->keyBy('phone');

        $this->table(['Número', 'Nombre', 'Cuándo', 'Consulta'], $originals->map(fn (WhatsappMessage $message) => [
            $message->phoneNumber(),
            $message->profile_name ?? '—',
            $message->localTime()->format('d/m H:i'),
            SendRetryInvitation::describe($message),
        ])->values()->all());

        if ($this->option('dry-run')) {
            $this->info("{$originals->count()} invitaciones por enviar (dry run, no se envió nada).");

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($originals as $message) {
            if (Cache::add("whatsapp:retry-invited:{$message->getKey()}", true, now()->addDays(7))) {
                SendRetryInvitation::dispatch($message);
                $sent++;
            }
        }

        $this->info(sprintf('%d invitaciones despachadas, %d ya enviadas antes.', $sent, $originals->count() - $sent));

        return self::SUCCESS;
    }
}
