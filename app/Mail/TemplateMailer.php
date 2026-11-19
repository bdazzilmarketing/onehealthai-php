<?php

namespace App\Mail;

/** Sends one of the application's email templates. */
interface TemplateMailer
{
    /**
     * Renders and sends a template. Any delivery failure throws.
     *
     * @param  array<string, mixed>  $templateData
     * @param  string|null  $idempotencyKey  dedupes retries of the same logical send; without it every call sends
     * @return bool true when the message was accepted for delivery (or was already delivered under this key)
     */
    public function send(string $template, string $to, array $templateData, ?string $idempotencyKey = null): bool;
}
