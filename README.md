# Witness

Witness is a self-hosted simple e-signature application for sending PDFs, collecting signatures,
and preserving evidence of who signed them.

## Who It Is For

Witness serves agencies and small back offices that send NDAs, contractor agreements, and
engagement letters. It gives them a flat-install alternative to per-envelope services while keeping
the signed document and its audit trail under their control.

## What It Does

This repository currently contains the Laravel and Built for Cloud foundation for Witness. The v1
product work follows the authoritative PRD and will add PDF envelopes, reusable templates, parallel
signers, typed and drawn signatures, completion certificates, public verification, lifecycle tools,
and MCP operations.

Witness produces simple electronic signatures only. It does not provide advanced or qualified
signatures, notarisation, identity-document verification, sequential routing, embedded signing,
webhooks, payments, or legal advice.

## Built For Cloud

Witness is a single Built for Cloud Laravel application intended for the Scalpels catalog. Its
manifest is maintained in `config/built-for-cloud.php`. Laravel Cloud owns production resource
configuration; do not commit or shadow Cloud-managed resource variables.

## Local Development

```bash
composer setup
composer dev
```

This app is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets
are committed under `public/build`.

## Quality Gate

```bash
composer ready
```

Tests run against PostgreSQL using the `witness_app_test` database. Run a focused test with, for
example, `./vendor/bin/pest tests/Integration/BuiltForCloudConfigurationTest.php`.

## Deployment

Artisan Build agents handling Laravel Cloud work must follow
`brain/skills/laravel-cloud-deploy/SKILL.md`. Start with read-only discovery. Credentials come from
the authenticated Laravel Cloud CLI; discover application and environment identifiers rather than
guessing or committing them. Keep `.cloud/config.json` uncommitted, and verify the application and
its attached resources after every deployment.

No Laravel Cloud application, environment, or resource is provisioned by this repository. Before a
future deploy, bind the Cloud CLI to the intended application, provision PostgreSQL, private object
storage, and a managed queue through Cloud, then verify the attached resources without writing
their values into repository configuration.

## Repository

<https://github.com/artisan-build/witness>
