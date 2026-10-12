<?php

namespace App\Services;

use App\Contracts\WhatsappSender;

/**
 * The web chat's end of the same delivery the WhatsApp job does: instead of
 * posting each message to Meta it keeps it, in order, for the page to draw.
 *
 * Going through deliver() rather than reading the WhatsappReply directly is
 * what keeps the two channels from drifting — the order of attachments, pin
 * and bodies, and which body each button rides on, are worked out in one place.
 */
class WebChatSender implements WhatsappSender
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $bubbles = [];

    public function send(string $to, string $body): void
    {
        $this->bubbles[] = ['tipo' => 'texto', 'texto' => $body];
    }

    public function sendWithButtons(string $to, string $body, array $buttons): void
    {
        $this->bubbles[] = ['tipo' => 'texto', 'texto' => $body, 'botones' => $buttons];
    }

    public function sendWithList(string $to, string $body, string $label, array $rows): void
    {
        $this->bubbles[] = ['tipo' => 'texto', 'texto' => $body, 'lista' => ['etiqueta' => $label, 'filas' => $rows]];
    }

    public function sendDocument(string $to, string $url, string $caption, string $filename): void
    {
        $this->bubbles[] = ['tipo' => 'documento', 'url' => $url, 'texto' => $caption, 'archivo' => $filename];
    }

    public function sendLocation(string $to, float $latitude, float $longitude, string $name, string $address): void
    {
        $this->bubbles[] = [
            'tipo' => 'ubicacion',
            'latitud' => $latitude,
            'longitud' => $longitude,
            'nombre' => $name,
            'direccion' => $address,
        ];
    }

    public function indicateTyping(string $inboundMessageId): void {}

    public function referencing(string $reference): static
    {
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bubbles(): array
    {
        return $this->bubbles;
    }

    /**
     * What went out, as the message log stores a reply: one string per message.
     *
     * @return array<int, string>
     */
    public function transcript(): array
    {
        return array_map(fn (array $bubble) => match ($bubble['tipo']) {
            'ubicacion' => "📍 {$bubble['nombre']} ({$bubble['latitud']}, {$bubble['longitud']})",
            default => $bubble['texto'],
        }, $this->bubbles);
    }
}
