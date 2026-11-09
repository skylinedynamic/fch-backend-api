# BUG-052: a requested card stays with the borrower after its minimums rise

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-052 (R2-OP-CACC-02). A client with FICO 760 marked Business Platinum Card (minimum 700) as Requested. The host then raised the card's FICO minimum to 800.
- The card disappeared from the client's Credit Accounts list.
- Apply answered 404, so the client could not move their own request to Processing or Approved.
- The request was still in the data. Setting the minimum back to 700 showed it again.

**Cause:** the list and apply both use `CreditAccount::offeredTo()`. It checked only whether the borrower meets the card's minimums now, not whether they had already applied. A drop in the borrower's own FICO would hide the card the same way.

## Solution
`offeredTo()` now also includes a card the borrower already has an application for, even when they no longer meet its FICO or time-in-business minimum.
- A borrower who has not applied is still not offered the card.
- An Inactive card is still hidden, request or not, as `available()` runs first (BUG-053).

## Files changed
- `app/Models/CreditBuilder/CreditAccount.php`: `scopeOfferedTo`, the minimums OR an application by this borrower.

## Testing
- **New:** `BorrowerCreditBuilderApiTest::a_card_already_requested_stays_with_the_borrower_after_its_minimums_rise`. It failed first. It covers:
  - the card is still listed after both minimums rise;
  - the borrower can still set it to Approved;
  - another borrower who never applied is not offered it, and gets a 404 on apply;
  - Inactive still hides it.
- **Run:** `tests/Feature/CreditBuilder` + `tests/Integration/CreditBuilder`, 183 passed.
- **client-fch:** no change. The page shows what the API lists and does no FICO filtering of its own.

## Open items
- None.
