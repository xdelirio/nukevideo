@AGENTS.md

## Claude Code

The guide above is shared by every coding agent; this part only applies to Claude Code.

- **Slash commands** (`.claude/commands/`): `/endpoint` adds an API endpoint end to end with its
  docs entry, `/migration` creates a migration in this repository's shape, `/pint` formats PHP and
  `/test` runs the Pest suite in the API container.
- **Hooks** (`.claude/hooks/`, wired in `.claude/settings.json`): after every edit, Pint formats a
  PHP file and ESLint `--fix` runs on a front file, both inside the running containers. They never
  block an edit except to hand back ESLint errors that `--fix` could not settle.
- **Permissions**: the shared `.claude/settings.json` only pre-approves read-only git commands and
  the project's own checks, and denies reading `.env` files and `storage/*.key`. Anything broader is
  personal and belongs in `.claude/settings.local.json`, which git ignores.
