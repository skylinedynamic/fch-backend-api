<?php

namespace App\Mail\Traits;

use App\Models\Common\AppConfiguration;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends each message with the sending tenant's own mail credentials.
 *
 * Credentials are database-only. config/mail.php and config/services.php carry
 * none, so there is nothing behind this to silently fall back to: whatever
 * EmailConfigurationService::resolveFor() returns is what the message is sent
 * with, and when it returns nothing the message is not sent at all.
 *
 * That last part is the point. Previously an unconfigured partner fell through
 * to the .env mailbox, so their mail went out through whichever server the
 * deployment happened to point at - under credentials belonging to someone
 * else, with no indication anything was wrong. A logged failure is the better
 * outcome: it is visible, and it cannot deliver a tenant's mail from another
 * tenant's mailbox.
 */
trait MailConfig
{
    protected ?int $mailPartnerId = null;

    /**
     * Transports that carry no credentials and address no one.
     *
     * `log` and `array` are how local work and the test suite avoid sending
     * anything real, so a tenant's SMTP settings must not override them -
     * otherwise MAIL_MAILER=log would still open a socket to a live server.
     */
    private const NON_NETWORK_TRANSPORTS = ['log', 'array', 'null', 'failover'];

    /**
     * Every config key this trait writes, and therefore must put back.
     *
     * The previous version restored `mail.default` and the from/bcc keys but
     * NOT `mail.mailers.smtp.*`, so a partner's host, username and password
     * survived the send. In a queue worker - long-lived, processing many
     * tenants in sequence - the next partner's mail then went out through the
     * previous partner's server. Anything written below is listed here.
     */
    private const MANAGED_KEYS = [
        'mail.default',
        'mail.from.address',
        'mail.from.name',
        'mail.bcc',
        'mail.mailers.smtp.host',
        'mail.mailers.smtp.port',
        'mail.mailers.smtp.username',
        'mail.mailers.smtp.password',
        'mail.mailers.smtp.encryption',
        'services.ses.key',
        'services.ses.secret',
        'services.ses.region',
    ];

    /**
     * How long a queued message keeps being retried.
     *
     * NOT `tries()`. SendQueuedMailable reads `tries` as a PROPERTY:
     *
     *     $this->tries = property_exists($mailable, 'tries') ? $mailable->tries : null;
     *
     * so a method of that name is silently ignored and the job falls back to the
     * worker's --tries, which defaults to 1. `retryUntil()` and `backoff()` are
     * both read with method_exists(), and a deadline overrides that default - so
     * this is how a Mailable actually asks for retries without every deployment
     * remembering to pass --tries on the command line.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds((int) config('queue.mail_retry_window', 300));
    }

    /**
     * Determine the number of seconds to wait before retrying the job.
     */
    public function backoff(): int
    {
        return config('queue.mail_backoff', 60);
    }

    /**
     * Set the partner ID for dynamic SMTP configuration.
     */
    public function setMailPartnerId(?int $partnerId = null): self
    {
        if ($partnerId) {
            $this->mailPartnerId = $partnerId;
            return $this;
        }

        $partner = getConfigPartner();
        $this->mailPartnerId = $partner?->id;

        return $this;
    }

    /**
     * Apply the sending tenant's mail configuration, then send.
     */
    public function send($mailer)
    {
        $partnerId = $this->mailPartnerId;

        // Local work and the test suite: nothing leaves the machine, so there
        // are no credentials to resolve and nothing to override.
        if (in_array((string) config('mail.default'), self::NON_NETWORK_TRANSPORTS, true)) {
            return $this->deliver($mailer, $partnerId);
        }

        $config = app(EmailConfigurationService::class)->resolveFor($partnerId);

        if (!$config) {
            // Deliberately not sent. See the class docblock: delivering this
            // through some ambient mailbox would be worse than not delivering.
            Log::error('MailConfig: no usable mail configuration; message not sent.', [
                'mailable' => static::class,
                'partner_id' => $partnerId,
            ]);

            return null;
        }

        $original = $this->snapshot();

        try {
            $mailer = $this->apply($config, $partnerId);

            return $this->deliver($mailer, $partnerId);
        } finally {
            foreach ($original as $key => $value) {
                Config::set($key, $value);
            }

            // Drop mailers resolved against the tenant's settings, so the next
            // message resolves fresh rather than inheriting this one's.
            app('mail.manager')->forgetMailers();
        }
    }

    /**
     * Put the resolved credentials into config and re-resolve the mailer.
     */
    private function apply(\stdClass $config, ?int $partnerId)
    {
        $driver = ($config->email_provider ?? 'smtp') === 'aws_ses' ? 'ses' : 'smtp';
        $siteName = AppConfiguration::get('site_name', '', AppConfiguration::MODULE_BRANDING, $partnerId);

        Config::set('mail.default', $driver);

        if ($driver === 'smtp') {
            // smtp_port comes from a free-text settings field (see
            // StoreEmailConfigurationRequest), so guard against non-numeric
            // legacy values reaching Symfony's Dsn, which requires a strict
            // ?int and throws a TypeError otherwise.
            $port = $config->smtp_port ?? null;
            $port = (is_numeric($port) && (int) $port == $port) ? (int) $port : null;

            Config::set('mail.mailers.smtp.host', $config->smtp_host ?? null);
            Config::set('mail.mailers.smtp.port', $port);
            Config::set('mail.mailers.smtp.username', $config->smtp_username ?? null);
            Config::set('mail.mailers.smtp.password', $config->smtp_password ?? null);
            Config::set('mail.mailers.smtp.encryption', $config->smtp_encryption ?? null);
        } else {
            Config::set('services.ses.key', $config->aws_access_key ?? null);
            Config::set('services.ses.secret', $config->aws_secret_key ?? null);
            Config::set('services.ses.region', $config->aws_region ?? null);
        }

        Config::set('mail.from.address', $config->from_address ?? null);
        Config::set('mail.from.name', $siteName);
        Config::set('mail.bcc', $config->bcc_emails ?? null);

        // Set on the mailable directly: the envelope is resolved before this
        // runs, so a config change alone does not reach an already-built one.
        $this->from($config->from_address ?? null, $siteName);

        // Purge first, so the mailer below is built from the settings above
        // rather than handed a cached instance from a previous message.
        app('mail.manager')->forgetMailers();

        return app('mail.manager')->mailer($driver);
    }

    /**
     * The config values this trait is about to overwrite.
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (self::MANAGED_KEYS as $key) {
            $snapshot[$key] = config($key);
        }

        return $snapshot;
    }

    /**
     * Hand off to Laravel, keeping a failed send from breaking the caller.
     */
    private function deliver($mailer, ?int $partnerId)
    {
        try {
            return parent::send($mailer);
        } catch (TransportExceptionInterface $e) {
            // The mail server was reached and refused this attempt: a rate
            // limit, a timeout, a dropped connection. Retrying is the correct
            // response, and swallowing it here is what turned a "too many
            // emails per second" into two permanently lost payment
            // confirmations - the queue saw the job succeed and never retried.
            Log::warning('MailConfig: transport refused ' . static::class, [
                'partner_id' => $partnerId,
                'error' => $e->getMessage(),
                'will_retry' => $this instanceof ShouldQueue,
            ]);

            if ($this instanceof ShouldQueue) {
                // Back to the queue, to be retried per backoff()/retryUntil().
                // A permanent refusal (bad credentials, say) exhausts the window
                // and lands in failed_jobs, which is still the better outcome:
                // visible, and re-runnable once the settings are fixed.
                throw $e;
            }

            return null;
        } catch (\Throwable $e) {
            // Misconfiguration and our own guard rails - an invalid SMTP port
            // surfaces as a \TypeError from Symfony's transport factory, which
            // \Exception does not catch. No number of retries makes these
            // succeed, and a failed send must never break the business action
            // that triggered it (creating a lead, taking a payment), so it is
            // logged and bypassed.
            Log::error('MailConfig: failed to send ' . static::class, [
                'partner_id' => $partnerId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
