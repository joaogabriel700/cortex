# Cortex

A personal system for finances, tasks, habits, studies, goals and career, built as an installable, offline-capable web app (PWA).

> **Status:** project scaffold. No product features or screens yet.

**Stack:** Laravel API · Nuxt (Vue, TypeScript, PWA) · PostgreSQL 18 · Docker Compose

## Run the demo

Requirement: Docker with a recent Docker Compose.

```bash
git clone https://github.com/joaogabriel700/cortex.git
cd cortex
docker compose up
```

Open <http://localhost:8080>. API health check: <http://localhost:8080/api/health>.

No `.env` file is needed: random secrets are created on the first run and kept in Docker volumes. The demo listens on `127.0.0.1` only. A demo account with sample data will arrive with the first module.

`docker compose down -v` removes the demo **and deletes all its data**.

## Development

Keep the repository in the Linux file system when using WSL 2 (not under `/mnt/c`).

| Command | What it does |
|---|---|
| `make dev` | Hot reload: web on <http://localhost:3000>, mail catcher on <http://localhost:8025>, PostgreSQL on `127.0.0.1:5432` |
| `make migrate` / `make migrate-status` | Run or list migrations as `app_owner`. The `api` container cannot, on purpose |
| `make test` | API tests (Pest on PostgreSQL 18) and web type check and tests (Vitest) |
| `make demo` / `make down` | Start or stop the demo |

Other commands run inside the API container, for example `docker compose -f compose.yaml -f compose.dev.yaml exec api php artisan make:controller Name`.

If port 5432 is taken by another project, set `DEV_DB_PORT` (for example `DEV_DB_PORT=5433 make dev`).

After changing `composer.lock` or `package-lock.json` in development, install the new dependencies in the running containers (`composer install`, `npm ci`), because the dependency volumes are not rebuilt automatically.

## Git workflow

| Branch | Role |
|---|---|
| `main` | Stable version. Changes arrive only through a pull request from `develop` |
| `develop` | Integration branch. Feature pull requests target it |
| `feature/*`, `fix/*` | Short-lived branches created from `develop` |

1. Branch from `develop` and open a pull request back into `develop`.
2. To publish a version, open a pull request from `develop` into `main`.
3. Merge `develop` into `main` with a merge commit, not a squash, so the two branches do not drift apart.
4. An urgent fix may branch from `main`; merge it back into `develop` as well.

CI runs on pull requests and pushes for both branches.

## Layout

| Path | Content |
|---|---|
| `api/` | Laravel API (Sanctum, Pest) |
| `web/` | Nuxt single-page PWA (pt-BR and English), served by Caddy |
| `docker/` | PostgreSQL bootstrap script |
| `docs/` | Operations notes |
| `compose*.yaml` | Demo, development, test and production setups |

## Services and database roles

`web` serves the app and forwards `/api` and `/sanctum` to `api`. `worker` runs the queue, `scheduler` runs scheduled tasks and `migrate` applies migrations before they start. All PHP services use the same image.

The PostgreSQL superuser is used only to bootstrap the database. The API and the worker connect as `app_runtime` (no superuser, no `BYPASSRLS`), migrations run as `app_owner`, and only the scheduler also gets the `app_maintenance` credentials. The API refuses to start with a role that could bypass row level security.

The bootstrap script runs only on an empty database volume.

## Security at the edge

Caddy sends the security headers, `Cache-Control: no-store` on `/api` and `/sanctum`, no CORS, and per-route request body limits (21 MiB restore, 6 MiB import upload, 1 MiB sync, 512 KiB import confirmation, 64 KiB otherwise). The app's Content-Security-Policy is generated at build time with the hashes of Nuxt's inline scripts; `CSP_HEADER=Content-Security-Policy-Report-Only` switches it to report only. `/up` is internal to the containers. The development server on port 3000 does not apply these edge rules.

## Hosting

Hosting is not decided yet. The image keeps no state that must survive on local disk when `FILESYSTEM_DISK=s3`; sessions, cache and queue live in PostgreSQL. For hosts without always-on processes, the `tick` command runs the due recurring tasks and drains the queue once. It catches up on late or missed triggers, so register business tasks in `RecurringTaskServiceProvider`, not with `Schedule::`. If the host can only call HTTP, set `TICK_SECRET` (or `CRON_SECRET`), 32 characters or more, and send `POST /api/internal/tick` with `Authorization: Bearer <secret>`. Without a secret the endpoint returns 404.

Production on a single server uses `compose.prod.yaml` and `.env.example`. Read [backup and log retention](docs/backup-and-retention.md) before storing real data.

---

## Resumo em português

O Cortex é um sistema pessoal (finanças, tarefas, hábitos, estudos, objetivos e carreira) instalável como PWA e preparado para uso offline. Por enquanto existe só a estrutura do projeto.

Para rodar a demo, com Docker instalado:

```bash
docker compose up
```

Abra <http://localhost:8080>. Não é preciso criar `.env`: os segredos são gerados na primeira execução. Para desenvolver com recarga automática use `make dev`; para os testes, `make test`. Atenção: `docker compose down -v` apaga todos os dados da demo.
