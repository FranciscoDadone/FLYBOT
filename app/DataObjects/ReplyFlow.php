<?php

namespace App\DataObjects;

/**
 * A WhatsApp Flow offered under a message: one button that opens a form
 * inside WhatsApp instead of a conversation that asks one box at a time.
 *
 * The form itself lives at Meta -- it is published once and referenced by
 * $id -- so what travels per message is only which form, which screen to open
 * it on, and what to put in it. Everything the bot already knows goes in
 * $data, so the pilot opens a form that is mostly filled in and corrects it
 * rather than dictating it.
 *
 * A form is the one thing the bot sends that can fail for a reason belonging
 * to the reader rather than to the network -- a WhatsApp too old to draw one,
 * or a client that does not. So it carries what to say instead: the same
 * question asked the way the bot asked it before there were forms, which the
 * pilot answers by typing. Nothing is remembered about the failure, so a form
 * that failed once is offered again next time; a reader who can never take one
 * simply gets the conversation, every time, and never notices.
 *
 * $token is ours and comes back verbatim in the submission. It is what ties a
 * form to the draft it was sent for: without it a form filled in after the
 * draft expired would land on whatever draft happened to be open, which for a
 * flight plan means a document about a different flight.
 */
final readonly class ReplyFlow
{
    /**
     * @param  string  $id  The published Flow's id at Meta.
     * @param  string  $token  Ours, echoed back in the submission.
     * @param  string  $cta  The caption on the button that opens it, 30 characters at most.
     * @param  string  $screen  Which screen the form opens on.
     * @param  array<string, string>  $data  What that screen starts out filled in with.
     * @param  string  $mode  "published", or "draft" while a new version is being tried out.
     * @param  string  $fallback  What to say instead if the form cannot be delivered.
     */
    public function __construct(
        public string $id,
        public string $token,
        public string $cta,
        public string $screen,
        public array $data = [],
        public string $mode = 'published',
        public string $fallback = '',
        public ?ReplyButton $fallbackButton = null,
    ) {}
}
