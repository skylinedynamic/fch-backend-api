# Tests

## Running

```bash
php artisan test                                # everything
php artisan test --testsuite=Unit               # unit only
./vendor/bin/phpunit tests/Unit/EmbeddedForm    # one feature
./vendor/bin/phpunit --filter CreditScoreServiceTest
./vendor/bin/phpunit --testsuite=Unit --testdox
```

## Layout

One folder per **feature**. Everything belonging to a feature lives in its
folder, whichever layer of `app/` implements it — a feature is a thing the
product does, not a directory in the source tree.

```
tests/Unit/
└── EmbeddedForm/                     the public embedded funding application
    ├── SubmitEmbedFormRequestTest    what a submission must contain
    ├── OriginAllowListTest           which sites may post one
    ├── AttributeMappingTest          how a payload becomes columns
    ├── CreditScoreServiceTest        banding the applicant's score
    ├── PhoneHelpersTest              storing a phone number as E.164
    ├── UsPhoneNumberTest             the rule that guards it
    └── TrustedProxyTest              recording the real client IP
```

Those seven cover a FormRequest, a Service, a Rule, two global helpers and the
middleware configuration in `bootstrap/app.php`. Spread across the source tree
they sat in six different directories; they are one feature, so they are one
folder.

A class used by more than one feature is tested under the feature it was built
for. `CreditScoreService` also feeds the partner portal's submission list, but
it exists because of the Embedded Form, so its test lives here. If a second
feature grows its own behaviour on top of it, that behaviour gets a test under
that feature's folder.

Each test carries a `#[CoversClass]` attribute naming its subject where it has a
single one, so the class under test stays explicit even though the path no
longer spells it out.

## The two suites

**Unit** — no database, no HTTP, no mail. Pure logic, fast enough to run on
every save. Where a unit reads through a cache or a config value, the test seeds
that rather than reaching for the thing behind it; `CreditScoreServiceTest`
seeds the band cache so the service never queries `app_configurations`.

**Feature** — boots the framework and may query. Empty so far. Use the same
feature folder names as above when adding to it. `phpunit.xml` points it at an
in-memory SQLite database so a feature test can never touch a developer's own
MySQL data, but the migrations have to be run into that database before anything
there will pass.

## Conventions

- `#[Test]` attributes, not `test` prefixes.
- Test names are sentences: `a_debt_balance_sent_with_a_no_is_discarded`.
- `#[DataProvider]` for the same assertion over many inputs — boundary values in
  particular, where the provider key names the case
  (`'top of Fair' => [669, 'Fair']`).
- A test that exists because of a specific defect says so, so nobody
  "simplifies" the case away later.
