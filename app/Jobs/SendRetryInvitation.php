<?php

namespace App\Jobs;

use App\Models\WhatsappMessage;
use App\Services\MetaWhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells someone whose answer never reached them that it can be asked again.
 *
 * A template rather than a plain message because the user's 24-hour window has
 * usually closed by the time anyone notices. Tapping "Reintentar" opens a new
 * one, and the payload brings the original question back with it.
 */
class SendRetryInvitation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public function __construct(protected WhatsappMessage $original) {}

    public function handle(MetaWhatsappSender $sender): void
    {
        $sender->sendTemplate(
            $this->original->phone,
            (string) config('services.whatsapp.retry_template'),
            (string) config('services.whatsapp.retry_template_language'),
            [self::describe($this->original)],
            "retry:{$this->original->getKey()}",
        );
    }

    /**
     * Meta rejects template parameters with line breaks, tabs or long runs of
     * spaces.
     */
    public static function describe(WhatsappMessage $message): string
    {
        return Str::limit(Str::squish($message->body), 60);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('No se pudo enviar la invitación a reintentar.', [
            'phone' => $this->original->phone,
            'whatsapp_message_id' => $this->original->getKey(),
            'exception' => $e?->getMessage(),
        ]);
    }
}
