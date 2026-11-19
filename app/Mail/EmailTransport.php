<?php

namespace App\Mail;

/** Delivers one message. Any failure throws. */
interface EmailTransport
{
    public function name(): string;

    /** @param  array{from: string, to: string, subject: string, html: string, text: string, replyTo?: string}  $message */
    public function send(array $message): void;
}
