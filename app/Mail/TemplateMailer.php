<?php

namespace App\Mail;

/** Sends one of the application's email templates. */
interface TemplateMailer
{
    /**
     * @param  array<string, mixed>  $templateData
     * @return bool true when the message was accepted for delivery
     */
    public function send(string $template, string $to, array $templateData): bool;
}
