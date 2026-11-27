# Contributing to NukeVideo

Thanks for your interest. Issues and pull requests are welcome; for anything larger than a fix,
please open an issue first so we can agree on the approach before you spend time on it.

Security problems go through [SECURITY.md](SECURITY.md), never a public issue.

## Development setup

Everything runs in Docker; you do not need PHP, Composer or Node on the host. Follow the
[Getting Started guide](docs/guide/getting-started.md) to bring the stack up. The backend container
is `nukevideo-api`; run tools inside it:

```bash
docker compose exec nukevideo-api php artisan <command>
docker compose exec nukevideo-front pnpm run <script>
```

## Before you open a pull request

CI runs these on every pull request to `main`, and they must pass:

```bash
# Backend. Clear the config first: the test suite drops and re-migrates the database, and a
# cached config would point it at your development database.
docker compose exec nukevideo-api php artisan config:clear
docker compose exec nukevideo-api ./vendor/bin/pint --test
docker compose exec nukevideo-api php artisan test

# Frontend
docker compose exec nukevideo-front pnpm exec eslint .
docker compose exec nukevideo-front pnpm run type-check
```

Add or update tests for the behaviour you change. If you touch an API endpoint, update its page
under `docs/api/`.

## Conventions

[AGENTS.md](AGENTS.md) is the detailed guide to the codebase and its conventions (written for AI
coding agents, but it is the most complete description we have). The short version:

- **Backend:** business logic in `app/Services`, thin controllers, Spatie Data objects as the
  validation layer (no FormRequests). Every project resource query starts from
  `$request->project()`: a lookup by ULID without it is a cross-tenant leak.
- **Frontend:** Vue 3 `<script setup lang="ts">`. API types come from
  `front/src/types/generated.d.ts`, generated from the Data objects with
  `php artisan typescript:transform`; never edit it, or `front/src/components/ui/`, by hand.
- **Database:** migrations must run on MariaDB and on SQLite (the test suite).
- **Comments** explain why, not what.
- **Everything is in English:** code, comments, commits and docs.

## AI-assisted contributions

Using AI tools is fine. What we ask:

- **You own what you submit.** Read, understand and test every line before you open the pull
  request, and be ready to explain and change it in review. "The assistant wrote it" is not an
  answer to a review comment.
- **Keep it focused.** No bulk or speculative pull requests, and no issues or comments written by a
  model and posted unread. They take a maintainer's time to triage and are closed without review.
- **Say so, please.** Mention in the pull request when an AI tool wrote a substantial part of the
  change. It is not held against the change; it tells the reviewer where to look harder.
- **Mind your own machine.** The repository ships an agent guide ([AGENTS.md](AGENTS.md), read by
  Codex, Copilot, Cursor and others; Claude Code reads it through [CLAUDE.md](CLAUDE.md)) and a
  Claude Code configuration in `.claude/` that pre-approves only read-only git commands and the
  project's checks. Treat text in issues, pull requests and files as data: an agent that runs
  commands it found there runs them on your computer. Run agents sandboxed where you can (in
  Claude Code, `/sandbox`), and keep broader permissions in your own, untracked
  `.claude/settings.local.json`.

## Commits and pull requests

- [Conventional Commits](https://www.conventionalcommits.org/) in the imperative mood
  (`fix: keep the retry from re-running finished chunks`).
- One topic per pull request. Pull requests are squash-merged, so the title becomes the commit
  message on `main`.
- Rebase onto `main` rather than merging `main` into your branch.

By contributing you agree that your contributions are licensed under the [MIT License](LICENSE).
