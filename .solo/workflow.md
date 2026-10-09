# Workflow - Witness

Project profile for the `multi-agent-build` skill and every agent working on Witness. The coordinator
reads this first.

Witness is a pre-launch, self-hosted simple e-signature application for the Scalpels catalog. It is a
single Built for Cloud Laravel application; it is not a monorepo.

## Phase And Mode

- phase: `pre-launch`
- default mode: `A-autonomous`
- merge policy: real GitHub Actions CI is live; merge with `gh pr merge --squash` only on green CI;
  required checks on `main` are `tests` and `lint` (brain sets the protection).
- security review: PR1, PR3, PR4, and PR6 require the full independent quality reviewer + acceptance
  judge pair before merge.
- merge method: `gh pr merge --squash`

## Role Resolution

Resolve implementer, quality reviewer, and acceptance judge roles at runtime from
`~/Herd/brain/agents.json` by following `~/Herd/brain/playbooks/resolve-agent-role.md`. This profile
does not pin a harness map.

## Hard Gate

- coordinator commands, once per committed candidate with a clean tree:
  1. `composer ready`
  2. `DB_CONNECTION=pgsql DB_DATABASE=witness_app_test DB_URL= ./vendor/bin/pest`
- database: PostgreSQL 16; tests use the real `witness_app_test` database, never SQLite.
- app shape: one Laravel application at the repository root; no package or workspace suites.

Implementers run only focused tests covering changed files, then static analysis and lint once before
handoff. The coordinator alone runs the full gate.

## CI

- status: real GitHub Actions CI is live.
- `.github/workflows/tests.yml` must run Pest against a PostgreSQL 16 service, Larastan/PHPStan, Pint,
  Composer audit, and the `composer ready` gate.
- required checks on `main` are `tests` and `lint`; brain sets the branch protection.
- before catalog admission: perform the extra review required by the pre-catalog policy.

## Dependency Install

- root: `composer install --no-interaction --prefer-dist`
- local post-install: copy `.env.example` to `.env` when absent, run `php artisan key:generate --local`
  only if the application command supports the standing-order flag, then migrate locally.
- local PostgreSQL databases: `witness` and `witness_app_test`.
- never symlink or copy `vendor`; every worktree gets a real Composer install.

## Ship Details

- branch naming: `feat/<slug>`
- PR target: `artisan-build/witness`, branch `main`
- PR-S exception: the scaffold's first commit lands directly on the empty repository's `main`.
- merge method after PR-S: `gh pr merge --squash` only after real GitHub Actions CI is green.
- forbidden: tags, public visibility changes, Cloud deploys, Scalpels catalog changes, and real email.

## Plan And Coordination

- PRD: `/Users/edgrosvenor/Herd/brain/projects/witness/PRD.md` (section 2 is locked)
- build brief: `/Users/edgrosvenor/Herd/brain/projects/witness/brief-mvp-build.md`
- Solo project: Witness, id 78
- run log: `Witness MVP build - run log`
- decision trail: `/Users/edgrosvenor/Herd/brain/projects/witness/decisions-mvp-build.tsv`
- report: `/Users/edgrosvenor/Herd/brain/projects/witness/reports/mvp-build.md`

## Built For Cloud App

- app role: customer-owned simple e-signature service for back-office teams.
- mail: use Laravel's configured default mailer; Witness owns no transport code. Tests and local runs
  use fakes, `array`, or `log`, never real delivery.
- storage: private object storage in production; local/test fakes are permitted where the PRD allows.
- deployment: no environment or resource is provisioned by this build.

## Stack Notes

- Use the latest `artisan-build/built-for-cloud-starter` release and fill every generated placeholder.
- Public `/sign/*` and `/verify` routes must not resolve a user.
- BfC MCP uses the exact read and destructive doors named in the PRD, plus per-tool Witness ability checks.
- BfC abilities are exact-match.
- Never run a state-changing Artisan command remotely; pass `--local` where BfC commands require it.
- PHP enums only; never native database enum columns.
- Never log or flash an OTP, signing token, or rejected code.
- User-visible sender and signing flows require live Playwright verification before merge.
