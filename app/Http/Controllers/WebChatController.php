<?php

namespace App\Http\Controllers;

use App\DataObjects\WhatsappReply;
use App\Jobs\Concerns\DeliversWhatsappReply;
use App\Models\WhatsappMessage;
use App\Services\WebChatSender;
use App\Services\WhatsappBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class WebChatController extends Controller
{
    use DeliversWhatsappReply;

    public function __construct(protected WhatsappBotService $bot) {}

    /**
     * POST /chat
     *
     * The landing page's chat: the same bot, answering in the same messages
     * WhatsApp would get, synchronously because there is a browser waiting on
     * the request rather than a webhook that has to be acknowledged at once.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mensaje' => ['nullable', 'string', 'max:500'],
            'boton' => ['nullable', 'string', 'max:100'],
        ]);

        $body = trim((string) ($validated['mensaje'] ?? ''));
        $button = trim((string) ($validated['boton'] ?? '')) ?: null;

        if ($body === '' && $button === null) {
            return response()->json(['message' => 'Escribí un mensaje.'], 422);
        }

        $from = $this->chatId($request);
        $startedAt = microtime(true);

        $log = WhatsappMessage::create([
            'phone' => $from,
            'profile_name' => 'Chat web',
            'body' => $body,
            'button_payload' => $button,
        ]);

        try {
            $reply = $this->bot->reply($body, $from, $button);
            $log->recordUnderstanding($this->bot->lastContext());
        } catch (Throwable $e) {
            report($e);
            $log->recordUnderstanding($this->bot->lastContext());
            $log->recordFailure($e->getMessage());

            $reply = WhatsappReply::of('Tuve un problema procesando tu consulta. Probá de nuevo en unos minutos.');
        }

        $sender = new WebChatSender;
        $this->deliver($sender, $from, $reply);

        $log->recordReply($sender->transcript(), $startedAt);

        return response()->json(['mensajes' => $sender->bubbles()]);
    }

    /**
     * Who is writing, for as long as the session lasts. The bot keys its menus
     * on it, and the panel groups the conversation by it as it does a phone.
     */
    protected function chatId(Request $request): string
    {
        $session = $request->session();

        if (! $session->has('web_chat_id')) {
            $session->put('web_chat_id', 'web:'.Str::lower(Str::random(12)));
        }

        return (string) $session->get('web_chat_id');
    }
}
