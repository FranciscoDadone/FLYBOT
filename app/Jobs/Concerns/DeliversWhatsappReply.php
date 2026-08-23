<?php

namespace App\Jobs\Concerns;

use App\Contracts\WhatsappSender;
use App\DataObjects\ReplyButton;
use App\DataObjects\WhatsappReply;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends every attachment a WhatsappReply carries, then its pin if it has one,
 * then every body, each with whichever button or form outbound() paired it
 * with.
 */
trait DeliversWhatsappReply
{
    protected function deliver(WhatsappSender $sender, string $to, WhatsappReply $reply): void
    {
        foreach ($reply->documents as $document) {
            $sender->sendDocument($to, $document->url, $document->caption, $document->filename);
        }

        if ($reply->location !== null) {
            $sender->sendLocation(
                $to,
                $reply->location->latitude,
                $reply->location->longitude,
                $reply->location->name,
                $reply->location->address,
            );
        }

        foreach ($reply->outbound() as [$body, $button, $flow]) {
            if ($flow === null) {
                $this->deliverBody($sender, $to, $body, $button);

                continue;
            }

            // A form is the one thing here that can fail for a reason belonging
            // to the reader -- a WhatsApp too old to draw one -- rather than to
            // the network. Left to throw, that costs them the whole answer and
            // then costs it again on every retry, which is silence. What they
            // get instead is the same thing asked in words.
            try {
                $sender->sendWithFlow($to, $body, $flow);
            } catch (Throwable $e) {
                Log::warning('No se pudo mandar el formulario; va la pregunta escrita.', [
                    'to' => $to,
                    'flow' => $flow->id,
                    'error' => $e->getMessage(),
                ]);

                $this->deliverBody($sender, $to, $flow->fallback ?: $body, $flow->fallbackButton);
            }
        }
    }

    protected function deliverBody(WhatsappSender $sender, string $to, string $body, ?ReplyButton $button): void
    {
        if ($button === null) {
            $sender->send($to, $body);

            return;
        }

        if ($button->listLabel !== null) {
            $sender->sendWithList($to, $body, $button->listLabel, $button->buttons);

            return;
        }

        $sender->sendWithButtons($to, $body, $button->buttons);
    }
}
