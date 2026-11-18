# R3-BUG-013: a client's mails link to their own partner's client portal

Date: 2026-11-14

## Problem

Wanda (a white-label partner) › Clients › client 159 › Documents › Mark as Rejected. The "Your document was Rejected" mail came branded as Wanda, but its only link was `https://client-stage.skylinedynamic.dev/documents`. That is the host's client portal, where a Wanda client cannot sign in. The right portal was `wanda-borrower.skylinedynamic.dev`.

The mail is queued. Its link came from `getBorrowerDomain()` with no partner, which reads the signed-in user's partner. In the queue worker nobody is signed in, so it fell back to `app.frontend_borrower_url` (the host portal). Sent without a queue, it would have used the reviewer's partner instead.

The branding was right, because it already uses the client's partner id passed to the mail. The verification-status and change-request-status mails built their `loginUrl` the same way.

## Solution

All three mails take the portal from the client's partner (`getBorrowerDomain($this->mailPartnerId)`), the same id their branding uses:
- `BorrowerDocumentReviewedMail` (`/documents`)
- `BorrowerVerificationStatusMail` (`/login`)
- `ChangeRequestStatusMail` (`/login`)

**No host fallback.** It works like `BorrowerAccountCreatedMail`: a partner with no client portal of its own gets no link, never the host's. The document mail's "Go to My Documents" button is shown only when there is a link.

**Note:** the verification and change-request templates don't render `loginUrl` today, so nothing visible changes in those two. Their link data is now correct for when it is shown.

## Files changed

- `app/Mail/Borrower/BorrowerDocumentReviewedMail.php`, `BorrowerVerificationStatusMail.php`, `ChangeRequestStatusMail.php`: the link uses the client's partner, with no host fallback.
- `resources/views/emails/borrower/document_reviewed.blade.php`: the button is shown only with a link.
- `tests/Integration/Mail/BorrowerMailPortalLinkTest.php` (new, 13). For each mail:
  - a WL client's link is on its partner's portal, with nobody signed in
  - a signed-in host reviewer doesn't change it
  - a host client's link is on the host portal
  - a partner without a portal gets no link

  Plus the rendered document mail: the WL link is there, and there is no button without one.

## Testing

- **Seen failing first:** 10 of the 13. The 3 host-portal cases passed before too.
- **`tests/Integration/Mail`, `BorrowerDocumentsApiTest`, `VerificationNotifierTest`, `ChangeRequestNotifierTest`:** 136 passed.
- **Full suite (after R3-BUG-011 to 014):** 5,562 tests, all passed (2 skipped).

## Open items

- **Staging check:** Wanda has `borrower_domain` set (wanda-borrower.skylinedynamic.dev), so the retest should show that link. A WL partner without one now gets a mail with no button.
