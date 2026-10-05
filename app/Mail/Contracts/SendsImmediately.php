<?php

namespace App\Mail\Contracts;

/**
 * A Mailable that is sent inline, during the request, rather than queued.
 *
 * Every Mailable implements either this or ShouldQueue (pinned by
 * tests/Unit/Mail/MailQueuePolicyTest). Queued is the default. This is only for
 * messages a person is actively waiting on - account credentials, a password
 * reset, a security code - and those carry a plaintext password or code, which
 * must not sit serialized in the jobs and failed_jobs tables.
 *
 * Laravel already sends anything without ShouldQueue inline; this interface
 * adds nothing at runtime. It exists so that "not queued" is a stated decision
 * rather than an omission.
 */
interface SendsImmediately
{
}
