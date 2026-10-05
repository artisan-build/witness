# Witness

Witness is a self-hosted simple e-signature application for agencies and small back offices that
need to send a PDF, collect signatures, and preserve proof of who signed it. It is a single Built
for Cloud Laravel application intended for the Scalpels catalog. The audit trail is the product.

Witness v1 is limited to simple electronic signatures. Do not add advanced or qualified signatures,
notarisation, identity-document verification, sequential routing, bulk send, conditional fields,
non-PDF uploads, embedded signing, webhooks, integrations, payments, signer accounts, or saved
signatures unless the authoritative PRD changes.

## Workflow

See `.solo/workflow.md` for this app's repository, merge policy, hard gate, CI, and coordination
details. Complete that file before delegating work.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## No Frontend Build Step

Non-negotiable: **no Node, no npm, no Vite, no frontend build step.** Do not introduce one.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. A class absent
from that bundle silently does nothing. To use new classes, delete the existing bundle, run
`php artisan tailwind:optimize`, verify the classes landed, and commit the regenerated file. The
optimizer uses a standalone Tailwind binary and does not introduce Node tooling.

## Built For Cloud

The app manifest is maintained in `config/built-for-cloud.php`. Witness uses the package-provided
authentication and UI foundation. Product MCP abilities and tools are added only in their planned
slice. Witness sends mail through Laravel's configured default mailer and owns no transport code.

Laravel Cloud provisions resource configuration. Do not commit secrets, app IDs, personal config,
machine-specific paths, or hand-written values that shadow Cloud-managed resource variables.

Tests use PostgreSQL and the `witness_app_test` database, never SQLite.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose because PHPStan scans the
model helper. `.phpstorm.meta.php` is gitignored because it embeds absolute local paths. Do not make
these files consistent in either direction.

## Static Analysis

The PHPStan baseline lives in `phpstan-baseline.neon`. Keep it shrinking; never grow it silently.
