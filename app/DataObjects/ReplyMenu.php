<?php

namespace App\DataObjects;

/**
 * The follow-up offered after answering about an aerodrome: "want anything
 * else about it?", with a button for each of the other topics.
 *
 * It rides on the answer's last message when that one can carry it, and
 * only goes out as a message of its own when it cannot — see
 * WhatsappReply::outbound(). The body is what it says in that case.
 */
final readonly class ReplyMenu
{
    public function __construct(
        public string $body,
        public ReplyButton $button,
    ) {}
}
