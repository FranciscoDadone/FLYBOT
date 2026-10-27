<?php

namespace App\DataObjects;

/**
 * Everything the bot wants to say in answer to one message: the files to
 * attach, the bodies to send, in order, optionally a button to offer
 * underneath, and optionally a menu offering the other topics for the same
 * aerodrome.
 *
 * The button belongs to the reply rather than to each body because it is only
 * ever attached to the last one. A long answer arrives as a numbered run of
 * messages, and repeating the same button on every part of it would read as
 * several offers rather than one.
 *
 * The documents lead: an attachment carries its own caption, so it says what it
 * is on its own, and whatever text follows is about the whole set of them
 * rather than an introduction to files that have not arrived yet. A pin travels
 * the same way and for the same reason — it names the aerodrome underneath
 * itself, so anything said after it is a follow-up rather than an introduction.
 */
final readonly class WhatsappReply
{
    /**
     * @param  array<int, string>  $messages
     * @param  array<int, ReplyDocument>  $documents
     */
    public function __construct(
        public array $messages,
        public ?ReplyButton $button = null,
        public ?ReplyMenu $menu = null,
        public array $documents = [],
        public ?ReplyLocation $location = null,
    ) {}

    public static function of(string ...$messages): self
    {
        return new self(array_values($messages));
    }

    /**
     * @param  array<int, string>  $messages
     */
    public static function ofMany(array $messages, ?ReplyButton $button = null): self
    {
        return new self(array_values($messages), $button);
    }

    /**
     * @param  array<int, ReplyDocument>  $documents
     * @param  array<int, string>  $messages  What to say after the attachments, if anything.
     */
    public static function ofDocuments(array $documents, array $messages = [], ?ReplyButton $button = null): self
    {
        return new self(array_values($messages), $button, null, array_values($documents));
    }

    /**
     * A pin, and nothing else to say: what the location row answers with. The
     * menu that follows it is attached the ordinary way, so the reader still
     * gets somewhere to go from a message that carries no buttons of its own —
     * WhatsApp draws none on a location.
     */
    public static function ofLocation(ReplyLocation $location): self
    {
        return new self([], null, null, [], $location);
    }

    public function withMenu(?ReplyMenu $menu): self
    {
        return new self($this->messages, $this->button, $menu, $this->documents, $this->location);
    }

    /**
     * WhatsApp's cap on the body of a message that carries buttons or a list.
     */
    public const MAX_INTERACTIVE_BODY_LENGTH = 1024;

    /**
     * The bodies to send, each paired with the button that rides on it — the
     * last answer body with $button, everything else with none. The one place
     * this pairing is worked out, so producing a reply never has to restate it.
     *
     * The menu rides on the last body too whenever it can, because every
     * message past the free monthly allowance is billed and a menu of its own
     * is one more. It cannot when that body is too long to carry actions or
     * already carries a list of its own, and then it goes out after it.
     *
     * @return array<int, array{0: string, 1: ?ReplyButton}>
     */
    public function outbound(): array
    {
        $last = count($this->messages) - 1;
        $button = $this->button;
        $menu = $this->menu;

        if ($menu !== null && $last >= 0 && $this->canCarryMenu($this->messages[$last], $button)) {
            $button = $button === null ? $menu->button : $menu->button->leadingWith($button);
            $menu = null;
        }

        $pairs = [];

        foreach ($this->messages as $i => $message) {
            $pairs[] = [$message, $i === $last ? $button : null];
        }

        if ($menu !== null) {
            $pairs[] = [$menu->body, $menu->button];
        }

        return $pairs;
    }

    protected function canCarryMenu(string $body, ?ReplyButton $button): bool
    {
        return mb_strlen($body) <= self::MAX_INTERACTIVE_BODY_LENGTH && $button?->listLabel === null;
    }
}
