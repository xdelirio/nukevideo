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

[CLAUDE.md](CLAUDE.md) is the detailed guide to the codebase and its conventions (it is written for
AI assistants, but it is the most complete description we have). The short version:

- **Backend:** business logic in `app/Services`, thin controllers, Spatie Data objects as the
  validation layer (no FormRequests). Every project resource query starts from
  `$request->project()`: a lookup by ULID without it is a cross-tenant leak.
- **Frontend:** Vue 3 `<script setup lang="ts">`. API types come from
  `front/src/types/generated.d.ts`, generated from the Data objects with
  `php artisan typescript:transform`; never edit it, or `front/src/components/ui/`, by hand.
- **Database:** migrations must run on MariaDB and on SQLite (the test suite).
- **Comments** explain why, not what.
- **Everything is in English:** code, comments, commits and docs.

## Commits and pull requests

- [Conventional Commits](https://www.conventionalcommits.org/) in the imperative mood
  (`fix: keep the retry from re-running finished chunks`).
- One topic per pull request. Pull requests are squash-merged, so the title becomes the commit
  message on `main`.
- Rebase onto `main` rather than merging `main` into your branch.

By contributing you agree that your contributions are licensed under the [MIT License](LICENSE).
