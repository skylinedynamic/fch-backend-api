<?php

namespace Tests\Feature\Auth\Support;

use App\Models\User\User;
use App\Services\Global\Common\PortalIsolationService;

/**
 * Binds a PortalIsolationService double into the container so HTTP-level Auth
 * tests that are not specifically exercising domain isolation don't have to
 * fight it. Tests that DO care about domain isolation should NOT use this
 * trait — they should seed a real Partner + send matching Host/X-Domain
 * headers instead.
 */
trait BypassesPortalIsolation
{
    protected function bypassPortalIsolation(bool $allowed = true): void
    {
        $this->app->bind(PortalIsolationService::class, function () use ($allowed) {
            return new class($allowed) extends PortalIsolationService
            {
                public function __construct(private bool $allowed) {}

                public function isUserAllowedOnDomain(User $user, string $domain): bool
                {
                    return $this->allowed;
                }
            };
        });
    }
}
