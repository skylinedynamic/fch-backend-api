<?php

/**
 * The FCRA credit authorisation and E-SIGN consent a client gives before we pull
 * their credit.
 *
 * The wording lives here, in one place, because it is not decoration: it is the
 * thing being agreed to, and iSoftPull can ask us to produce it months later.
 * Every consent record copies the text below into itself along with `version`,
 * so a record always shows what that client actually saw.
 *
 * CHANGING THE WORDING: publish a NEW version on fourcornerholdings.com and bump
 * `version` here. Never edit an existing version's text - the published page
 * keeps a version history, and stored records point at a version string that has
 * to stay resolvable against it.
 */
return [

    /**
     * Stored with every record, as a string. '1.0' and not 1.0: a float renders
     * as "1" and would stop matching the version history on the public page.
     */
    'version' => '1.0',

    /**
     * The permanent public copy of the authorisation text below.
     *
     * Not a link the client must click to agree - the full wording is already in
     * the form. It is cited in the record and in the PDF so that whoever audits
     * us can read the exact wording and its effective date at source, which is
     * stronger evidence than our own PDF quoting itself.
     */
    'authorization_url' => 'https://fourcornerholdings.com/credit-authorization',

    /**
     * The document behind checkbox 2. The words "Electronic Disclosures and
     * Communications" link here, in a new tab.
     *
     * NOT /disclosures - that page describes the website and platform and is not
     * a consent document.
     */
    'e_consent_url' => 'https://fourcornerholdings.com/e-consent',

    'privacy_url' => 'https://fourcornerholdings.com/privacy',

    'terms_url' => 'https://fourcornerholdings.com/terms',

    /** Checkbox 1 - the FCRA authorisation itself. */
    'credit_authorization_text' => 'You understand that by clicking "Submit" immediately following this notice, you are providing "written instructions" under the Fair Credit Reporting Act, authorizing Four Corner Holdings, LLC to obtain information from your personal credit profile and other information from Equifax, TransUnion, and/or Experian to conduct a prequalification for credit. This process will not affect your credit score. You also give permission for your contact information to be provided to a third-party lender, if applicable.',

    /** Checkbox 2 - E-SIGN consent, legally separate from the authorisation. */
    'e_consent_text' => 'I certify that I have read and agree to the Electronic Disclosures and Communications.',

    /**
     * Shown under the checkboxes. Information only - nothing is agreed to here,
     * so it carries no checkbox and is not stored as an acceptance.
     */
    'prequalification_notice' => 'Prequalification Notice: This credit review is for preliminary evaluation purposes only. It does not constitute an application approval, offer of credit, financing commitment, or guarantee of funding.',

];
