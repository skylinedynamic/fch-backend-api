<?php

namespace Tests\Unit\Mail;

use App\Mail\Traits\MailConfig;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Mail credentials are tenant data, never environment configuration.
 *
 * Every partner sends through their own mail server, and a partner without one
 * sends through the host's. Both live in `email_configurations`. The environment
 * used to hold a set of credentials behind all of that, which meant an
 * unconfigured partner's mail went out through whatever mailbox the deployment
 * happened to point at - under someone else's credentials, with nothing to
 * indicate it had happened. These pin that there is no longer anything behind
 * the tenant's own configuration to fall through to.
 *
 * No database: these read config and the trait's own source.
 */
#[CoversClass(EmailConfigurationService::class)]
#[CoversClass(MailConfig::class)]
class TenantMailCredentialsTest extends TestCase
{
    private mixed $originalMailer = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The retry stubs must reach deliver() without a credential lookup, and
        // no test may leak a transport change into the next one.
        $this->originalMailer = config('mail.default');
        config(['mail.default' => 'array']);
    }

    protected function tearDown(): void
    {
        config(['mail.default' => $this->originalMailer]);

        parent::tearDown();
    }

    // -- Nothing sensitive left in config ------------------------------

    #[Test]
    public function the_smtp_mailer_carries_no_credentials_at_rest(): void
    {
        foreach (['username', 'password', 'url', 'scheme'] as $key) {
            $this->assertNull(
                config("mail.mailers.smtp.{$key}"),
                "mail.mailers.smtp.{$key} still has a value; it must come from the tenant's record"
            );
        }
    }

    #[Test]
    public function the_default_transport_is_constructible_but_goes_nowhere(): void
    {
        // Laravel resolves the default mailer eagerly - Mail::to() builds the
        // transport before the Mailable's send() runs - and Symfony's Dsn
        // requires a string host, so a null there is a TypeError on every send
        // before MailConfig can apply the tenant's settings. The placeholder
        // exists only to survive that construction.
        $this->assertSame('localhost', config('mail.mailers.smtp.host'));
        $this->assertSame(25, config('mail.mailers.smtp.port'));

        // It must not be anyone's real mail server.
        $this->assertNull(config('mail.mailers.smtp.username'));
        $this->assertNull(config('mail.mailers.smtp.password'));
    }

    #[Test]
    public function the_ses_mailer_carries_no_credentials_at_rest(): void
    {
        foreach (['key', 'secret', 'region'] as $key) {
            $this->assertNull(
                config("services.ses.{$key}"),
                "services.ses.{$key} still has a value; it must come from the tenant's record"
            );
        }
    }

    #[Test]
    public function the_environment_is_not_consulted_for_credentials(): void
    {
        // Set the variables the old config read, then re-read the config files.
        // Anything that picks them back up would reintroduce the silent
        // fallback, whatever the value happens to be on this machine.
        foreach ([
            'MAIL_HOST' => 'smtp.leaked.example',
            'MAIL_USERNAME' => 'leaked-user',
            'MAIL_PASSWORD' => 'leaked-pass',
            'MAIL_PORT' => '2525',
        ] as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }

        try {
            $mail = require config_path('mail.php');
            $services = require config_path('services.php');

            // The placeholder stands whatever the environment says.
            $this->assertSame('localhost', $mail['mailers']['smtp']['host']);
            $this->assertSame(25, $mail['mailers']['smtp']['port']);
            $this->assertNull($mail['mailers']['smtp']['username']);
            $this->assertNull($mail['mailers']['smtp']['password']);
            $this->assertNull($services['ses']['key']);
            $this->assertNull($services['ses']['secret']);
        } finally {
            foreach (['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_PORT'] as $name) {
                putenv($name);
                unset($_ENV[$name]);
            }
        }
    }

    // -- What counts as a usable configuration -------------------------

    private function config(array $values): \stdClass
    {
        return (object) $values;
    }

    #[Test]
    public function an_smtp_configuration_needs_a_host_and_a_username(): void
    {
        $service = app(EmailConfigurationService::class);

        $this->assertTrue($service->isUsable($this->config([
            'email_provider' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'someone',
        ])));

        $this->assertFalse($service->isUsable($this->config([
            'email_provider' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => '',
        ])));

        $this->assertFalse($service->isUsable($this->config([
            'email_provider' => 'smtp',
            'smtp_host' => '',
            'smtp_username' => 'someone',
        ])));
    }

    #[Test]
    public function an_ses_configuration_needs_a_key_and_a_secret(): void
    {
        $service = app(EmailConfigurationService::class);

        $this->assertTrue($service->isUsable($this->config([
            'email_provider' => 'aws_ses',
            'aws_access_key' => 'AKIA...',
            'aws_secret_key' => 'secret',
        ])));

        $this->assertFalse($service->isUsable($this->config([
            'email_provider' => 'aws_ses',
            'aws_access_key' => 'AKIA...',
        ])));

        // The SMTP fields must not stand in for the SES ones.
        $this->assertFalse($service->isUsable($this->config([
            'email_provider' => 'aws_ses',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'someone',
        ])));
    }

    #[Test]
    public function an_empty_row_is_not_usable(): void
    {
        // The settings screen creates every key as soon as it is opened, so a
        // row full of blanks is the normal state of a partner who has not
        // configured anything.
        $service = app(EmailConfigurationService::class);

        $this->assertFalse($service->isUsable(null));
        $this->assertFalse($service->isUsable($this->config([])));
        $this->assertFalse($service->isUsable($this->config([
            'email_provider' => 'smtp',
            'smtp_host' => null,
            'smtp_username' => null,
        ])));
    }

    // -- The restore contract ------------------------------------------

    #[Test]
    public function every_config_key_the_trait_writes_is_also_restored(): void
    {
        // The bug this guards: the trait used to write mail.mailers.smtp.* but
        // restore only mail.default and the from/bcc keys, so one partner's host,
        // username and password survived the send. In a queue worker the next
        // partner's mail then went out through the previous partner's server.
        $source = file_get_contents((new ReflectionClass(MailConfig::class))->getFileName());

        preg_match_all("/Config::set\(\s*'([^']+)'/", $source, $matches);
        $written = array_unique($matches[1]);

        $this->assertNotEmpty($written, 'found no Config::set calls - has the trait been rewritten?');

        $reflection = new ReflectionClass(MailConfig::class);
        $restored = $reflection->getConstant('MANAGED_KEYS');

        foreach ($written as $key) {
            $this->assertContains(
                $key,
                $restored,
                "{$key} is written but never restored; it will leak into the next message"
            );
        }
    }

    // -- Retrying a transient failure ----------------------------------

    #[Test]
    public function a_transport_failure_is_rethrown_for_a_queued_mailable(): void
    {
        // Mailtrap answering "550 too many emails per second" lost two payment
        // confirmations outright: deliver() swallowed it, so the queue recorded
        // the job as successful and never retried. A transport refusal is the
        // one failure retrying actually fixes.
        $mailable = new QueuedTransportFailureMailable();

        $this->expectException(\Symfony\Component\Mailer\Exception\TransportException::class);

        $mailable->sendAndCatch();
    }

    #[Test]
    public function a_transport_failure_is_swallowed_for_an_unqueued_mailable(): void
    {
        // Nothing is going to retry it, and a failed send must not break the
        // business action that triggered it.
        $mailable = new UnqueuedTransportFailureMailable();

        $this->assertNull($mailable->sendAndCatch());
    }

    #[Test]
    public function an_unqueued_mailable_can_ask_to_have_a_transport_failure_rethrown(): void
    {
        // For a sender that retries the refusal itself, instead of a queue.
        $mailable = new UnqueuedRethrowingTransportFailureMailable();

        $this->expectException(\Symfony\Component\Mailer\Exception\TransportException::class);

        $mailable->sendAndCatch();
    }

    #[Test]
    public function a_configuration_error_is_never_retried(): void
    {
        // A bad port or a missing host fails identically on every attempt, so
        // retrying only delays the log entry. Swallowed even when queued.
        $mailable = new QueuedConfigErrorMailable();

        $this->assertNull($mailable->sendAndCatch());
    }

    #[Test]
    public function the_retry_window_is_expressed_in_a_way_the_queue_actually_reads(): void
    {
        // SendQueuedMailable reads `tries` as a property, so a tries() METHOD is
        // ignored - which is why the old one never took effect. retryUntil() and
        // backoff() are both read with method_exists().
        $mailable = new QueuedTransportFailureMailable();

        $this->assertTrue(method_exists($mailable, 'retryUntil'));
        $this->assertTrue(method_exists($mailable, 'backoff'));
        $this->assertFalse(
            property_exists($mailable, 'tries'),
            'a tries property would silently override the retry window'
        );

        config(['queue.mail_retry_window' => 300]);
        $this->assertEqualsWithDelta(300, $mailable->retryUntil()->getTimestamp() - now()->getTimestamp(), 5);
    }

    #[Test]
    public function local_and_test_transports_are_left_alone(): void
    {
        // MAIL_MAILER=log and the array mailer the suite runs on carry no
        // credentials and address no one. A tenant's SMTP settings must never
        // override them, or a local run would open a socket to a live server.
        $reflection = new ReflectionClass(MailConfig::class);
        $exempt = $reflection->getConstant('NON_NETWORK_TRANSPORTS');

        $this->assertContains('log', $exempt);
        $this->assertContains('array', $exempt);

        // The suite itself must be on one of them, or these tests could send.
        // setUp() pins this, so read what the suite was configured with.
        $this->assertContains($this->originalMailer, $exempt);
    }
}


/**
 * Stubs for the retry tests.
 *
 * Each is a real Mailable using the trait, handed a mailer whose send() throws
 * the failure under test - so deliver()'s decision is exercised without a mail
 * server, a database or a queue.
 */
abstract class FailingMailable extends \Illuminate\Mail\Mailable
{
    use \App\Mail\Traits\MailConfig;

    public function __construct()
    {
        $this->html('<p>stub</p>')->to('sink@example.test')->from('from@example.test');
    }

    /** A mailer that fails the way this stub is testing. */
    abstract public function failingMailer(): object;

    public function sendAndCatch()
    {
        return $this->send($this->failingMailer());
    }
}

class QueuedTransportFailureMailable extends FailingMailable implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function failingMailer(): object
    {
        return new class {
            public function send($view, $data, $callback)
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException(
                    'Expected response code "354" but got code "550", with message "550 5.7.0 Too many emails per second."'
                );
            }
        };
    }
}

class UnqueuedTransportFailureMailable extends FailingMailable
{
    public function failingMailer(): object
    {
        return new class {
            public function send($view, $data, $callback)
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('transient');
            }
        };
    }
}

class UnqueuedRethrowingTransportFailureMailable extends UnqueuedTransportFailureMailable
{
    protected bool $rethrowTransportFailures = true;
}

class QueuedConfigErrorMailable extends FailingMailable implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function failingMailer(): object
    {
        return new class {
            public function send($view, $data, $callback)
            {
                throw new \TypeError('Dsn::__construct(): Argument #2 ($host) must be of type string, null given');
            }
        };
    }
}
