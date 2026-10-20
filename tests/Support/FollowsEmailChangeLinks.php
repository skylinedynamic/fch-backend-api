<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Follows an email-change link the way its portal page does: the emailed link
 * opens on the portal (…/email-change/{action}/{user}/{hash}?expires&signature),
 * and the page calls the API's JSON endpoint at the same path under /api/v1
 * with the query passed along unchanged.
 */
trait FollowsEmailChangeLinks
{
    protected function emailChangeApiUrl(string $portalUrl): string
    {
        $parts = parse_url($portalUrl);

        return '/api/v1' . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** Opening the link: reads it, changes nothing. */
    protected function openEmailChangeLink(string $portalUrl): TestResponse
    {
        return $this->getJson($this->emailChangeApiUrl($portalUrl));
    }

    /** Submitting the link's page: confirm, cancel or undo. */
    protected function submitEmailChangeLink(string $portalUrl, array $data = []): TestResponse
    {
        return $this->postJson($this->emailChangeApiUrl($portalUrl), $data);
    }
}
