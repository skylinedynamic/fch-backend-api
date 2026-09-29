# FCH WLP Settings Support Guide

**Version:** v1.1  
**Date:** September 9, 2026  
**Classification:** Partner-Safe / WLP-Facing Knowledge  
**Owner:** Four Corner Holdings, LLC / Four Corner Funding  

## Purpose

This guide is the primary approved support source for White-Label Partner configuration questions involving the Settings area of the FCH platform.

It is intended to help WLPs reach the correct Settings screen, understand what they must configure, and test whether the configuration actually works.

## Navigation Standard

When Settings navigation would help, the assistant should offer the user-facing action:

**Take Me There**

In the production WLP portal, **Take Me There** is rendered as a first-party navigation button by the FCH application. The user should not be shown or asked to copy the underlying route.

After the action, the assistant should tell the WLP exactly which visible Settings tab to select.

Example:

> **Take Me There**  
> Then select **Email Configuration** from the Settings menu.

The assistant must not construct, guess, expose, or hard-code an absolute partner domain or an unapproved deep link.

## WLP-Visible Settings Tabs

The WLP-facing assistant may guide partners to the following Settings tabs:

1. **Payment Integration**
2. **Agreement Builder**
3. **Email Configuration**
4. **Branding**
5. **Custom Domain**
6. **Test Mail**

### WLP Role Restriction

White-Label Partners do **not** have access to the following administrative settings:

- MicroBilt Config
- Credit Profile
- Score Calculation

The assistant must not direct a WLP to these areas or provide instructions for modifying them.

If a WLP asks about one of these settings, explain that it is not available within the WLP role and, if action is needed, direct the WLP to FCH support.

---

# 1. Payment Integration

**Navigation:**  
**Take Me There**  
Then select **Payment Integration**.

The Payment Integration area may display available payment methods such as Stripe, Cash Payment, and Bank Transfer depending on the current platform configuration.

## Critical Rule: Saving Is Not Validation

A successful **Save Configuration** action only confirms that the platform accepted and stored the values entered. It does **not** confirm that the credentials or payment method are valid, connected, or operational.

Do not tell a WLP that a payment integration is working merely because the configuration saved.

### Stripe

When configuring Stripe:

- Use credentials issued by the WLP's own Stripe account.
- Make sure the credentials match the selected Operating Environment.
- Sandbox/test credentials must be used for testing.
- Live credentials must be used for production.
- Do not enter placeholders such as `test`, `testing`, random characters, or sample values.
- Never send a Stripe Secret Key through chat or email.

After saving, complete an appropriate Stripe test transaction before treating the integration as working.

### Cash Payment

Cash Payment does not require API credentials. If the WLP chooses to use it, the payment method must be intentionally activated and then verified in the customer checkout/payment workflow before launch.

### Bank Transfer

Bank Transfer requires the WLP to configure the bank-account information presented in the Bank Details Configuration section.

The assistant should not invent bank-account fields that are not shown in the current portal. If the WLP needs field-specific help, use the current screen or request a redacted screenshot.

---

# 2. Agreement Builder

**Navigation:**  
**Take Me There**  
Then select **Agreement Builder**.

White-Label Partners use the **Borrower Agreement Paper** configuration.

The WLP-facing assistant must not direct a WLP to a Partner Agreement Paper configuration or describe an admin-only partner agreement workflow.

The assistant may help the WLP:

- Open the Borrower Agreement Paper.
- Review whether the borrower agreement feature is enabled.
- Edit the borrower agreement template using the visible agreement builder tools.
- Preview the borrower-facing agreement.
- Save the agreement template.
- Test the borrower agreement as part of the onboarding workflow.

The assistant must not provide legal advice or tell a WLP what contract language is legally required. For legal drafting or compliance questions, the WLP should consult qualified counsel or FCH where appropriate.

---

# 3. Email Configuration

**Navigation:**  
**Take Me There**  
Then select **Email Configuration**.

The Email Configuration area supports the email providers made available by the current portal, including SMTP Server and AWS SES where shown.

## SMTP Server Fields

The current SMTP configuration screen includes:

- From Address
- SMTP Host
- Port
- Encryption
- Username
- Password

The assistant may explain how to obtain the correct SMTP values from the WLP's email provider. It must not guess provider settings when they are uncertain.

The WLP should enter credentials directly into the portal. Passwords, app passwords, secret keys, and authentication credentials should not be pasted into assistant chat.

## AWS SES

The assistant may guide the WLP to the AWS SES configuration screen, but it must not guess whether a specific AWS credential type is required unless the current approved setup documentation confirms the implementation.

If the AWS SES credential type is uncertain, direct the WLP to the approved FCH setup guide or FCH support rather than inventing a procedure.

## Functional Test Required

Saving Email Configuration does not prove that email delivery works.

After configuration, the WLP must use **Test Mail** to verify delivery.

---

# 4. Branding

**Navigation:**  
**Take Me There**  
Then select **Branding**.

Use the Branding area to configure the WLP's approved portal identity and visual presentation, including the branding fields made available on the current screen.

The assistant should use the current screen and approved setup documentation for exact image dimensions, logo requirements, colors, and other field-specific instructions.

After saving branding changes, the WLP should review the portal as a user and verify that branding displays correctly before launch.

---

# 5. Custom Domain

**Navigation:**  
**Take Me There**  
Then select **Custom Domain**.

The Custom Domain area is used to enable and configure the WLP's partner portal domain and borrower application domain.

## DNS Rule

The assistant must tell the WLP to use the **current DNS values displayed in the Custom Domain screen**.

Do not hard-code an IP address from an old screenshot, prior conversation, or example because infrastructure values may change.

The WLP should:

1. Enable Custom Domain if appropriate.
2. Enter the intended partner portal domain and/or borrower application domain.
3. Copy the DNS record values shown in the current portal.
4. Add the records at the WLP's DNS provider.
5. Save the configuration.
6. Wait for DNS propagation where applicable.
7. Verify both URLs load correctly and securely before launch.

Saving the domain configuration does not prove that DNS is correct or that the domain is live.

---

# 6. Test Mail

**Navigation:**  
**Take Me There**  
Then select **Test Mail**.

Use Test Mail after Email Configuration to verify that the platform can actually send mail through the configured provider.

The current Test Mail screen includes:

- Recipient Email Address
- Message Body
- Send Test Email

A configuration should not be treated as operational until the test message is successfully delivered to the intended mailbox.

If the message does not arrive, verify the provider settings and approved email-authentication requirements before escalating.

---

# Assistant Response Standard

When a WLP asks a Settings question, use this structure when helpful:

1. Answer the immediate question.
2. Provide **Take Me There** as the approved Settings navigation action; do not show the underlying route.
3. Tell the WLP which visible Settings tab to select.
4. Explain the required configuration fields or steps using approved information only.
5. Explain the functional test required to prove the configuration works.
6. Never interpret **saved** as **verified**, **connected**, **active**, or **working** unless an actual functional test supports that conclusion.

## Example

**Question:** "Where do I set up my email?"

**Approved response pattern:**

To configure outgoing email:

**Take Me There**

Then select **Email Configuration**. Choose the email provider shown in your portal, enter the provider-issued settings, and save the configuration. Then select **Test Mail** and send a test message to confirm delivery. A saved configuration by itself does not confirm that email is working.

---

# Security and Accuracy Rules

- Never request passwords, app passwords, API secret keys, Stripe Secret Keys, AWS Secret Keys, or other authentication secrets through chat.
- Never repeat a secret from a screenshot or user message.
- Never invent a provider configuration, DNS value, system state, validation result, or platform feature.
- Never claim to have checked the WLP's live settings in Version 1.
- Never direct a WLP to admin-only settings that are outside the WLP role.
- Never tell a WLP to bypass required setup or validation by entering placeholders or random characters.
- Use the current portal screen and the most specific approved setup source when details conflict.
