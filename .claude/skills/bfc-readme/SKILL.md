---
name: bfc-readme
description: Write an app-facing README from the Built for Cloud manifest and observable repository facts without carrying starter-kit identity into the app.
---

# Built for Cloud README

Use this skill to replace the scaffold README or refresh an app README from current facts.

1. Run `php .claude/skills/bfc-readme/scripts/inspect.php --json` from the app root.
2. Use `linked` values as facts. Present `inferred` values explicitly as guesses and confirm them when material. Leave `unattributed` details as TODOs or ask the user; never turn them into claims.
3. Inspect relevant app code for user-visible capabilities before describing them. Describe only behavior you can link to code or the user's answers; distinguish planned work.
4. Draft an app-facing `README.md` covering purpose, audience, current capabilities, Built for Cloud product link, local development, focused tests/quality gate, deployment, and repository. Preserve useful app-specific content and ask before replacing a non-scaffold README.
5. Remove all `{{FILL: ...}}` markers. Verify every product assertion is linked, user-supplied, or visibly labelled inferred.

Never copy from the starter kit's root README, `CLAUDE.md`, `.solo/workflow.md`, repository identity, branch policy, or maintainer workflow. The generated app is not `artisan-build/built-for-cloud-starter`. Do not claim Cloud resources, environments, app IDs, credentials, or shipped features that are not observable.
