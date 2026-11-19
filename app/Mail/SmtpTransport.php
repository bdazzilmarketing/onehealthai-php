<?php

namespace App\Mail;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * SMTP delivery. With implicit TLS the connection is encrypted from the
 * start (port 465); otherwise STARTTLS is required before anything is sent.
 * TLS 1.2 is the minimum either way.
 */
final class SmtpTransport implements EmailTransport
{
    private ?EsmtpTransport $transport = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $secure,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
    ) {}

    public function name(): string
    {
        return 'smtp';
    }

    public function send(array $message): void
    {
        $email = (new Email)
            ->from(Address::create($message['from']))
            ->to(Address::create($message['to']))
            ->subject($message['subject'])
            ->html($message['html'])
            ->text($message['text']);
        if (! empty($message['replyTo'])) {
            $email->replyTo(Address::create($message['replyTo']));
        }
        $this->transport()->send($email);
    }

    private function transport(): EsmtpTransport
    {
        if ($this->transport === null) {
            $transport = new EsmtpTransport($this->host, $this->port, $this->secure);
            if (! $this->secure) {
                $transport->setAutoTls(true);
                $transport->setRequireTls(true);
            }
            $stream = $transport->getStream();
            $options = $stream->getStreamOptions();
            $options['ssl']['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            $stream->setStreamOptions($options);
            if ($this->user) {
                $transport->setUsername($this->user);
                $transport->setPassword($this->password ?? '');
            }
            $this->transport = $transport;
        }

        return $this->transport;
    }
}
