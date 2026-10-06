# Witness

Witness is a self-hosted simple e-signature application for agencies and small back offices. It
sends one PDF to parallel signers, collects typed or drawn signatures, and preserves an audit trail
and Certificate of Completion.

## Capabilities

- Prepare PDF envelopes, assign signer fields, send invitations, and monitor each signer.
- Reuse PDFs and role-based field placement as templates.
- Require an emailed verification code and electronic-record consent before signing.
- Stamp completed fields into the PDF and deliver the final document to the sender and signers.
- Verify a completed final PDF without storing the uploaded file.
- Remind signers, expire or void envelopes, and permanently delete eligible envelopes with a
  separate confirmation step.
- Read envelope and template status or perform approved lifecycle actions through the Built for
  Cloud MCP endpoints.

Witness produces simple electronic signatures only. It does not provide advanced or qualified
signatures, digital seals, notarisation, identity-document verification, or legal advice. See the
[setup guide](docs/setup.md) for document exclusions and the limits of the audit chain.

## Built For Cloud

Witness is a single Built for Cloud Laravel application for the
[Scalpels catalog](https://scalpels.app/products/witness). Its public landing page opens the
package-provided authentication and dashboard flow. The application uses Laravel's configured
default mailer unchanged; Scalpels sending is the default.

Set a permanent custom domain before sending the first envelope. Signing links and certificate
verification URLs preserve the host used when they are created.

- [Installation setup](docs/setup.md)
- [Scalpels post-provision handoff](docs/scalpels-handoff.md)

Both documents are generated from `App\Support\SetupInstructions`, the same source rendered by the
authenticated setup page. Regenerate and verify them locally with:

```bash
composer docs
composer docs:check
```

## Local Development

```bash
composer install
composer dev
```

Witness is nodeless. Browser assets are committed under `public/build`; do not add Node, npm, Vite,
or a frontend build step. Tests use PostgreSQL and the `witness_app_test` database, never SQLite.

Run a focused test with:

```bash
./vendor/bin/pest tests/Feature/LandingDocumentationTest.php
```

The complete repository quality gate is:

```bash
composer ready
```

## Deployment

Deploy Witness as a Laravel Cloud application with PostgreSQL, private object storage, and a managed
queue. Laravel Cloud supplies resource configuration; do not commit credentials or shadow
Cloud-managed resource values. Follow the [setup guide](docs/setup.md) after provisioning.

## Repository

<https://github.com/artisan-build/witness>
