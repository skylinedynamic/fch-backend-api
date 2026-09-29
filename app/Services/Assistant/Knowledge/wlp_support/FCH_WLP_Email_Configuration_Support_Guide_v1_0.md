# FCH WLP Email Configuration Support Guide

**Version:** v1.0  
**Date:** September 9, 2026  
**Classification:** Partner-Safe / WLP-Facing Knowledge  
**Owner:** Four Corner Holdings, LLC / Four Corner Funding  
**Applies to:** WLP Settings -> Email Configuration and Settings -> Test Mail

## Purpose

This guide is the primary approved support source for White-Label Partner questions about outgoing platform email.

It covers:

- how to reach Email Configuration;
- how to choose between SMTP Server and AWS SES where available;
- provider-specific SMTP settings that have been verified against current provider documentation;
- how to prove that a saved configuration actually works;
- email authentication and deliverability checks;
- safe troubleshooting without exposing credentials.

The platform uses outgoing email for notifications, receipts, and system alerts. A configuration that saves successfully is not automatically usable.

---

# 1. Navigation

## Email Configuration

**Take Me There** -> `/admin/settings`  
Then select **Email Configuration**.

The current WLP Email Configuration screen supports these provider modes when shown:

- **SMTP Server**
- **AWS SES**

## Test Mail

After saving Email Configuration:

**Take Me There** -> `/admin/settings`  
Then select **Test Mail**.

The current Test Mail screen includes:

- Recipient Email Address
- Message Body
- Send Test Email

---

# 2. Critical Rule: Save Is Not Validation

A successful **Save Configuration** action confirms only that the portal accepted and stored the values entered.

It does **not** prove that:

- the SMTP host is real;
- the port and encryption match;
- the username or password is correct;
- an app password is valid;
- SMTP authentication is enabled;
- the sending address is authorized;
- Amazon SES is out of sandbox;
- SPF, DKIM, or DMARC is correct;
- the message will reach a recipient;
- the configuration is production-ready.

Do not describe email as **connected**, **verified**, **working**, or **operational** solely because the configuration saved.

A functional email test is required.

---

# 3. Credential Safety

Never send or paste any of the following into support chat or email:

- mailbox passwords;
- app passwords;
- SMTP passwords;
- AWS secret access keys;
- API keys;
- SMTP tokens;
- other authentication secrets.

Enter credentials only into the authenticated WLP portal or the applicable provider console.

If a credential has already been shared with another person or exposed in a screenshot, revoke or rotate it and replace the saved value in the portal.

When asking FCH for troubleshooting help, screenshots should show the field names and non-secret settings but must redact passwords, secret keys, API keys, and tokens.

---

# 4. SMTP Server Field Map

The current SMTP Server configuration screen includes:

| Portal Field | What belongs there |
|---|---|
| **From Address** | The sender address the platform should use. It must be permitted by the provider and should align with the authenticated sending account/domain. |
| **SMTP Host** | The provider's exact outgoing SMTP server hostname. Do not guess. |
| **Port** | The provider's published SMTP submission port. |
| **Encryption** | The encryption method that matches the selected port, normally SSL/TLS or TLS/STARTTLS. |
| **Username** | Usually the full sending email address. Some transactional providers use a fixed or token-based username. |
| **Password** | The provider-required mailbox password, app password, API key, or SMTP token. Never paste it into chat. |

## Port and Encryption Rule

Do not mix port and encryption types.

Common secure patterns are:

- **465** -> SSL/TLS where the provider supports implicit TLS;
- **587** -> TLS/STARTTLS where the provider supports SMTP submission.

Always follow the specific provider's published setting rather than assuming a generic port.

---

# 5. Recommended Provider Order

For a production WLP tenant, prefer a dedicated transactional sending service over a free consumer mailbox.

Recommended support order:

1. **Amazon SES** when the dedicated AWS SES configuration has been confirmed for the tenant.
2. A dedicated transactional SMTP provider such as **Twilio SendGrid** or **Postmark**.
3. A custom-domain business mailbox such as **Google Workspace**, **Microsoft 365**, **Zoho Mail**, **Namecheap Private Email**, **Titan**, or **Fastmail**.
4. Free consumer mailboxes only for limited testing where FCH explicitly supports them.

Free consumer email accounts are not preferred for live client notifications.

---

# 6. Google Workspace / Gmail SMTP

Use this setup when the WLP is sending through a Google Workspace mailbox or a supported Gmail account.

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | The Google Workspace/Gmail sending address or a provider-authorized alias |
| **SMTP Host** | `smtp.gmail.com` |
| **Port** | `587` recommended, or `465` |
| **Encryption** | TLS/STARTTLS for 587; SSL/TLS for 465 |
| **Username** | Full Google Workspace/Gmail email address |
| **Password** | Google **App Password** where required |

## Important Requirements

- Google App Passwords require **2-Step Verification**.
- App Password availability may be restricted by the Google Workspace administrator or account security configuration.
- A regular Google account password should not be substituted when an App Password is required.
- Google can revoke App Passwords after the Google Account password changes. If email stops working after a password change, generate a new App Password and update Email Configuration.

## WLP Support Pattern

If the WLP says, "My Gmail password works in Gmail but not in the portal," explain that the SMTP integration may require a Google App Password rather than the normal account password.

Do not ask the WLP to provide the App Password.

## Production Note

A free `@gmail.com` account may be used for controlled testing, but a custom-domain business sender or dedicated transactional service is preferred before live-client use.

---

# 7. Microsoft 365 SMTP

Use this only for a Microsoft 365 business mailbox where authenticated SMTP submission is available.

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | The Microsoft 365 mailbox being used to send, or an address the authenticated mailbox has permission to send as |
| **SMTP Host** | `smtp.office365.com` |
| **Port** | `587` |
| **Encryption** | TLS/STARTTLS |
| **Username** | Full Microsoft 365 mailbox address |
| **Password** | Mailbox credential only where the tenant still permits SMTP AUTH Basic Authentication |

## Important Requirements

- **Authenticated SMTP** may need to be enabled for the mailbox.
- Microsoft 365 can reject the connection if SMTP AUTH is disabled at the mailbox or tenant level.
- If the From Address differs from the authenticated mailbox, the mailbox may require **Send As** permission.

## 2026 Transition Warning

The current FCH SMTP screen uses username/password-style SMTP authentication and does not display an OAuth sign-in flow.

Microsoft has announced that:

- through December 2026, SMTP AUTH Basic Authentication behavior remains unchanged;
- at the end of December 2026, it will be disabled by default for existing tenants, although administrators can still enable it where Microsoft permits;
- new tenants created after December 2026 will have SMTP AUTH Basic Authentication unavailable by default and OAuth will be the supported authentication method;
- Microsoft plans to announce the final removal date in the second half of 2027.

For that reason, Microsoft 365 username/password SMTP should be treated as a **transitional** configuration, not the preferred long-term sending architecture for new WLP production tenants.

Where practical, use Amazon SES or another dedicated transactional sender for durability.

## Consumer Outlook Rule

Free Outlook.com, Hotmail, and MSN accounts are not approved as a standard WLP production sending configuration under the current FCH setup guidance.

Do not give a WLP consumer-Outlook setup path as though it is supported.

---

# 8. Zoho Mail

Zoho uses different SMTP hostnames depending on account type.

## Paid Organization / Custom Domain

| Portal Field | Value |
|---|---|
| **From Address** | The Zoho-hosted custom-domain mailbox |
| **SMTP Host** | `smtppro.zoho.com` |
| **Port** | `587` or `465` |
| **Encryption** | TLS for 587; SSL for 465 |
| **Username** | Full Zoho Mail address |
| **Password** | Zoho password or application-specific password where required |

## Personal / Free Organization Where Supported

| Portal Field | Value |
|---|---|
| **From Address** | The Zoho mailbox |
| **SMTP Host** | `smtp.zoho.com` |
| **Port** | `587` or `465` |
| **Encryption** | TLS for 587; SSL for 465 |
| **Username** | Full Zoho Mail address |
| **Password** | Zoho password or application-specific password where required |

## Important Zoho Rule

Zoho states that the exact server configuration for an account is available inside that account and may vary by account type or data center.

If the WLP's Zoho account shows different SMTP server information than this guide, use the provider values shown inside the WLP's Zoho account rather than forcing a generic hostname.

---

# 9. Namecheap Private Email

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | The Namecheap Private Email mailbox |
| **SMTP Host** | `mail.privateemail.com` |
| **Port** | `587` or `465` |
| **Encryption** | TLS/STARTTLS for 587; SSL/TLS for 465 |
| **Username** | Full Private Email address |
| **Password** | Master or Application password for that mailbox |

Outgoing SMTP authentication must be enabled.

Do not use an unrelated website-hosting password unless it is actually the password for the mailbox.

---

# 10. Titan Email

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | The Titan mailbox |
| **SMTP Host** | `smtp.titan.email` |
| **Port** | `587` or `465` |
| **Encryption** | STARTTLS for 587; SSL for 465 |
| **Username** | Full Titan email address |
| **Password** | Titan mailbox password or application password where required |

## Important Requirements

- Third-party email access must be enabled in Titan where required.
- If Two-Factor Authentication is enabled, Titan may require an Application Password.

---

# 11. Fastmail

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | The Fastmail sending address |
| **SMTP Host** | `smtp.fastmail.com` |
| **Port** | `587` or `465` |
| **Encryption** | STARTTLS for 587; SSL/TLS for 465 |
| **Username** | Full Fastmail email address |
| **Password** | Fastmail **app-specific password** |

Fastmail states that the regular Fastmail password cannot be used for SMTP. Use an app-specific password.

---

# 12. Twilio SendGrid SMTP

Use this when the WLP already has an approved SendGrid account and wants a dedicated transactional SMTP sender.

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | A sender identity/domain verified in SendGrid |
| **SMTP Host** | `smtp.sendgrid.net` |
| **Port** | `587` recommended; 465 is supported for SSL |
| **Encryption** | TLS/STARTTLS for 587; SSL for 465 |
| **Username** | The exact string `apikey` |
| **Password** | The WLP's SendGrid API Key |

The API key should have the permissions required to send mail.

Never paste the API key into support chat.

---

# 13. Postmark SMTP

Use this when the WLP already has an approved Postmark account for transactional email.

## Verified SMTP Values

| Portal Field | Value |
|---|---|
| **From Address** | A Postmark verified Sender Signature or address under a verified domain |
| **SMTP Host** | `smtp.postmarkapp.com` for transactional mail |
| **Port** | `587` recommended; 2525 or 25 are also supported by Postmark |
| **Encryption** | STARTTLS/TLS for 587 |
| **Username** | Postmark SMTP token Access Key, or the Server API Token where configured as both username and password |
| **Password** | Corresponding Postmark SMTP Secret Key, or the Server API Token where configured as both |

Do not use port 465 for Postmark; current Postmark documentation says it does not support implicit SSL on 465.

If the WLP is unsure whether the account uses an SMTP Token or Server API Token, instruct them to open the Postmark server/message-stream SMTP settings and use the values displayed there.

---

# 14. Email Included With a Web Host / cPanel-Style Mailbox

SMTP settings for web-hosted email are provider-specific.

Do **not** guess the SMTP hostname.

Ask the WLP to obtain the outgoing mail settings from the hosting provider's own mailbox configuration page, often labeled:

- Connect Devices
- Mail Client Manual Settings
- Configure Mail Client
- SMTP / Outgoing Server

The WLP may send a **redacted screenshot** of that provider page if help is needed.

The screenshot should show:

- outgoing SMTP hostname;
- outgoing SMTP port;
- encryption/security method;
- username format;
- whether authentication is required.

It must not show the mailbox password.

Many hosts use port 465 with SSL/TLS or 587 with STARTTLS, but the assistant must not assume those values without the provider documentation.

---

# 15. Provider Not Listed

If the WLP's provider is not listed:

1. Do not guess.
2. Ask the WLP for the provider name.
3. Ask them to open the provider's published **outgoing SMTP** settings.
4. If necessary, request a redacted screenshot of that page.
5. Map only the published provider values into the portal's six fields.
6. Save the configuration.
7. Run Test Mail.

Never ask the WLP to send a password or secret.

---

# 16. AWS SES — Current Controlled Guidance

The dedicated AWS SES screen currently shows these fields:

- From Address
- Access Key
- Secret Key
- Region

## Important Implementation Note

AWS supports two different SES sending interfaces:

- **SES API / AWS SDK**, which uses an AWS Access Key ID and AWS Secret Access Key;
- **SES SMTP interface**, which uses separate region-specific SES SMTP credentials.

The current WLP portal field labels **Access Key + Secret Key + Region** align with the AWS SES API/SDK credential model.

However, the public FCH partner-setup troubleshooting page currently contains a statement telling users to confirm that they used SES SMTP credentials rather than IAM access keys.

Those two signals conflict.

Until FCH development confirms which SES interface the current backend actually uses, the assistant must **not** tell a WLP to substitute SMTP credentials, IAM credentials, or another credential type into these fields based on inference.

For the current controlled support version:

- the assistant may explain the visible AWS SES fields;
- it may explain that the From Address must be authorized/verified in the same AWS Region used by the integration;
- it may explain that an SES account still in sandbox can only send to permitted/verified recipients;
- it may explain that production access is needed for normal unrestricted production sending;
- it must not give a credential-generation walkthrough until the backend interface is confirmed by FCH.

If a WLP needs AWS SES configured before that confirmation is published, escalate to FCH support.

---

# 17. SPF, DKIM, and DMARC

A message that sends successfully can still fail delivery or land in spam if the sending domain is not authenticated.

## SPF

SPF identifies which services are authorized to send for the domain.

**Critical rule: a domain should have only one SPF record.**

Do not add a second SPF TXT record to authorize another sender. Instead, update the existing SPF policy according to the provider's instructions.

## DKIM

DKIM provides cryptographic proof that the sending service is authorized for the domain.

DKIM records are generated or specified by the sending provider and then published in DNS.

Do not invent DKIM selectors or values.

## DMARC

DMARC tells receivers how to handle messages that fail SPF/DKIM alignment and provides reporting capability.

FCH's current partner-setup guidance recommends beginning in monitoring mode rather than jumping immediately to an enforcement policy.

Do not change an existing DMARC policy without understanding the domain's other senders.

---

# 18. Required Functional Verification

Configuration is not complete until the WLP verifies the following:

1. **Test Mail sends successfully** from Settings -> Test Mail.
2. The message arrives at the intended recipient.
3. The From name/address is the intended sender.
4. The message does not immediately land in spam.
5. Replying reaches a monitored inbox where replies are expected.
6. SPF, DKIM, and DMARC pass on a received message where applicable.

For launch testing, FCH recommends checking deliverability to both a Gmail and a Microsoft-hosted mailbox where practical because filtering behavior differs by receiving provider.

---

# 19. Troubleshooting Decision Tree

## A. "It saved, but Test Mail fails to send"

Likely causes include:

- wrong SMTP host;
- port/encryption mismatch;
- username format is wrong;
- regular password used where an app password is required;
- trailing or leading whitespace in a credential;
- SMTP AUTH disabled for the mailbox;
- provider has blocked third-party SMTP access.

What to do:

1. Compare each non-secret field with the provider's official outgoing SMTP settings.
2. Re-enter the credential directly in the portal without sharing it.
3. Confirm any provider-side SMTP/third-party access setting is enabled.
4. Save again.
5. Retry Test Mail.

## B. "Test Mail says sent, but nothing arrives"

Check:

- spam/junk;
- whether the From Address is authorized by the provider;
- whether the recipient address is correct;
- provider sending restrictions;
- SES sandbox/production status if using SES;
- SPF, DKIM, and DMARC.

## C. "The message arrives in spam"

Common causes:

- SPF failure;
- DKIM failure;
- DMARC alignment failure;
- From Address does not align with the authenticated domain;
- duplicate SPF records;
- poor sender reputation or use of a consumer mailbox for automated traffic.

Do not solve spam placement by randomly adding DNS records.

## D. "Email worked before and stopped after a password change"

For providers that use app passwords, the old app password may have been revoked.

Google specifically documents that App Passwords are revoked after the Google Account password changes.

Generate a new App Password in the provider account and update Email Configuration.

## E. "Authentication failed / 535 error"

Treat this as a credential or SMTP-authentication problem until evidence shows otherwise.

Check:

- full username/email format;
- app password vs normal password;
- SMTP AUTH enabled;
- correct provider host;
- correct port/encryption pair;
- no copied spaces.

Do not ask the WLP to paste the credential into chat.

---

# 20. Assistant Response Standard

When a WLP asks an email-configuration question, use this pattern when helpful:

1. Identify the provider.
2. Explain the required non-secret settings.
3. Provide **Take Me There** -> `/admin/settings`.
4. Tell the WLP to select **Email Configuration**.
5. Tell them to enter the secret credential directly in the portal, not chat.
6. Tell them to **Save Configuration**.
7. Then tell them to select **Test Mail** and run a functional test.
8. Explain what result proves success.
9. If the provider is unsupported, ambiguous, or account-specific, ask for the provider's published SMTP settings or a redacted screenshot rather than guessing.

## Example — Google Workspace

Use Google's SMTP server with your Google Workspace sending mailbox:

- SMTP Host: `smtp.gmail.com`
- Port: `587`
- Encryption: TLS/STARTTLS
- Username: your full Google Workspace email address
- Password: a Google App Password where required

**Take Me There** -> `/admin/settings`  
Then select **Email Configuration**.

Enter the App Password directly in the portal; do not send it through chat or email. Save the configuration, then select **Test Mail** and send yourself a test message. Treat the setup as working only after the message is actually received.

---

# 21. Escalation

Escalate to FCH when:

- AWS SES credential/interface behavior needs confirmation;
- the WLP uses a provider not covered by approved documentation and cannot obtain the provider's SMTP settings;
- Microsoft 365 requires an authentication method the current portal does not support;
- Test Mail repeatedly fails after the documented provider values are confirmed;
- DNS email-authentication changes are unclear or the domain already has complex SPF/DKIM/DMARC records;
- the issue appears to require backend logs or tenant-specific system inspection.

Use the standard FCH support escalation channel. Do not ask for passwords, app passwords, secret keys, API keys, or full authentication screenshots.

---

# 22. Source Verification Notes

Provider values in this guide were checked against current provider documentation on September 9, 2026.

FCH platform/setup source:

- https://fourcornerholdings.com/partner-setup

Google:

- https://support.google.com/a/answer/176600
- https://support.google.com/accounts/answer/185833

Microsoft 365:

- https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission
- https://learn.microsoft.com/en-gb/Exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365
- https://techcommunity.microsoft.com/blog/exchange/updated-exchange-online-smtp-auth-basic-authentication-deprecation-timeline/4489835

Zoho Mail:

- https://www.zoho.com/mail/help/zoho-smtp.html

Namecheap Private Email:

- https://www.namecheap.com/support/knowledgebase/article.aspx/1179/2175/general-private-email-configuration-for-mail-clients-and-mobile-devices/

Titan:

- https://support.titan.email/hc/en-us/articles/4405162224665-Configuring-Titan-on-Email-Scripts

Fastmail:

- https://www.fastmail.help/hc/en-us/articles/1500000278342-Server-names-and-ports

Twilio SendGrid:

- https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api

Postmark:

- https://postmarkapp.com/developer/user-guide/send-email-with-smtp

Amazon SES:

- https://docs.aws.amazon.com/ses/latest/dg/send-email-concepts-credentials.html
- https://docs.aws.amazon.com/ses/latest/dg/send-an-email-using-sdk-programmatically.html
- https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html
