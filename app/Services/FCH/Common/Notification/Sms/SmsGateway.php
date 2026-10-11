<?php

namespace App\Services\FCH\Common\Notification\Sms;

/**
 * The provider that actually delivers a text message. SmsChannel decides who
 * gets what; an implementation of this only sends it.
 *
 * To plug in a provider: implement this (the constructor receives the
 * partner's SmsConfiguration), list it under `providers` in config/sms.php
 * and offer it in a country there. SmsGatewayFactory builds it.
 */
interface SmsGateway
{
    /**
     * @param string $to      E.164 number, e.g. +15551234567
     * @param string $message Plain text, already rendered
     *
     * @throws \Throwable when the provider rejects the message, so the queued
     *                    notification is retried and finally recorded as failed
     */
    public function send(string $to, string $message): void;
}
