<?php

namespace App\Mail;

use RuntimeException;

/** Refuses any recipient that is not on the allowlist before the message reaches the driver. */
final class RestrictedTransport implements EmailTransport
{
    /** @param  list<string>  $allowed  lower-case addresses and @domain entries */
    public function __construct(private readonly EmailTransport $inner, private readonly array $allowed) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function send(array $message): void
    {
        $address = mb_strtolower(trim($message['to']), 'UTF-8');
        $at = strrpos($address, '@');
        $domain = $at === false ? substr($address, -1) : substr($address, $at);
        if (! in_array($address, $this->allowed, true) && ! in_array($domain, $this->allowed, true)) {
            throw new RuntimeException('Recipient is not on EMAIL_ALLOWED_RECIPIENTS');
        }
        $this->inner->send($message);
    }
}
