---
name: bfc-logo
description: Create a safe, usable SVG app icon at the path linked by the Built for Cloud manifest without overwriting an asset.
---

# Built for Cloud Logo

Use this skill after `config/built-for-cloud.php` has a validated manifest icon path.

1. Run `php .claude/skills/bfc-logo/scripts/create-logo.php --json` to create a simple SVG from the linked app name and icon path.
2. Optionally supply user-approved `--initials`, `--background`, and `--foreground` values. Without `--initials`, the script derives initials and labels them `inferred`.
3. Inspect the SVG in the app and report its manifest-linked path.

The script writes only the manifest's root-relative `.svg` target beneath `public/`. It rejects traversal and absolute filesystem paths and never overwrites an existing asset. If an asset exists, inspect it and ask before any manual replacement; never delete it silently. Do not invent a different path when the manifest path is missing or invalid: resolve the manifest first with `bfc-app-manifest`.
