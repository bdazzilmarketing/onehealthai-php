<?php

namespace App\Mail;

use App\Database\Db;
use App\Database\Tx;
use RuntimeException;

/**
 * Renders a registered template and sends it through the configured
 * transport (port of src/lib/email-templates/send-email.ts). A keyed send is
 * recorded before delivery, so a retry can never produce a duplicate.
 */
final class TemplateEmails implements TemplateMailer
{
    public const DEFAULT_FROM = 'One Health Estimator <noreply@notify.onehealth.bdazzil.dev>';

    /** A keyed send still marked "sending" after this long is treated as abandoned. */
    private const STALE_CLAIM_SECONDS = 10 * 60;

    private ?EmailTransport $transport = null;

    /**
     * @param  \Closure(): EmailTransport  $transports  builds the transport on first use
     * @param  \Closure(string): mixed  $env
     */
    public function __construct(private readonly Db $db, private readonly \Closure $transports, private readonly \Closure $env) {}

    private static function recipientHash(string $address): string
    {
        return hash('sha256', mb_strtolower(trim($address), 'UTF-8'));
    }

    /**
     * Records a keyed send before delivery. A key already delivered is skipped; a
     * key another worker is delivering right now is refused, so a retry can never
     * produce a duplicate while the first attempt may still succeed.
     *
     * @return 'send'|'already_sent'
     */
    private function claimDelivery(string $key, string $template, string $recipient): string
    {
        return $this->db->withServiceContext(function (Tx $tx) use ($key, $template, $recipient) {
            $inserted = $tx->select(
                "insert into public.email_deliveries (idempotency_key, template, recipient_hash, status)
                 values (?, ?, ?, 'sending')
                 on conflict (idempotency_key) do nothing
                 returning idempotency_key",
                [$key, $template, self::recipientHash($recipient)]);
            if (count($inserted) > 0) {
                return 'send';
            }
            $row = $tx->first(
                'select status, claimed_at < now() - make_interval(secs => ?) as stale
                 from public.email_deliveries where idempotency_key = ? for update',
                [self::STALE_CLAIM_SECONDS, $key]);
            if ($row === null) {
                throw new RuntimeException('Email delivery record disappeared');
            }
            if ($row['status'] === 'sent') {
                return 'already_sent';
            }
            if ($row['status'] === 'sending' && ! $row['stale']) {
                throw new RuntimeException('Email delivery already in progress');
            }
            $tx->execute(
                "update public.email_deliveries
                 set status = 'sending', attempts = attempts + 1, claimed_at = now()
                 where idempotency_key = ?",
                [$key]);

            return 'send';
        });
    }

    private function finishDelivery(string $key, string $status): void
    {
        $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
            "update public.email_deliveries
             set status = ?, sent_at = case when ? = 'sent' then now() else sent_at end
             where idempotency_key = ?",
            [$status, $status, $key]));
    }

    public function send(string $template, string $to, array $templateData, ?string $idempotencyKey = null, ?string $replyTo = null): bool
    {
        if (! in_array($template, EmailTemplates::NAMES, true)) {
            throw new RuntimeException("Template '{$template}' not found. Available: ".implode(', ', EmailTemplates::NAMES));
        }
        if ($to === '') {
            throw new RuntimeException('Recipient is required (the template defines no fixed recipient)');
        }

        $transport = $this->transport ??= ($this->transports)();
        $rendered = EmailTemplates::render($template, $templateData);

        if ($idempotencyKey !== null && $idempotencyKey !== '' && $this->claimDelivery($idempotencyKey, $template, $to) === 'already_sent') {
            return true;
        }
        $keyed = $idempotencyKey !== null && $idempotencyKey !== '';

        try {
            $transport->send([
                'from' => ($this->env)('EMAIL_FROM') ?: self::DEFAULT_FROM,
                'to' => $to,
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'text' => $rendered['text'],
            ] + ($replyTo ? ['replyTo' => $replyTo] : []));
        } catch (\Throwable $error) {
            if ($keyed) {
                try {
                    $this->finishDelivery($idempotencyKey, 'failed');
                } catch (\Throwable) {
                    // the claim goes stale and is retried later
                }
            }
            throw $error;
        }
        if ($keyed) {
            $this->finishDelivery($idempotencyKey, 'sent');
        }

        return true;
    }
}
