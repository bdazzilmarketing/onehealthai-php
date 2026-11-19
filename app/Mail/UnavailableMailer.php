<?php

namespace App\Mail;

/** Stands in until an email transport is configured: nothing is sent and the caller is told so. */
final class UnavailableMailer implements TemplateMailer
{
    public function send(string $template, string $to, array $templateData): bool
    {
        error_log('[email] not sent: no email transport is configured '.json_encode(['template' => $template]));

        return false;
    }
}
