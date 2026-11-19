<?php

namespace App\Mail;

use App\Support\Js;

/** Development only: writes the recipient and subject to the log, never the body. */
final class LogTransport implements EmailTransport
{
    /** @param  (\Closure(string): void)|null  $log */
    public function __construct(private readonly ?\Closure $log = null) {}

    public function name(): string
    {
        return 'log';
    }

    public function send(array $message): void
    {
        $line = "[email:log] to={$message['to']} subject=".Js::stringify($message['subject']);
        $this->log !== null ? ($this->log)($line) : error_log($line);
    }
}
