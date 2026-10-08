# DeskFlow API

Laravel 11 REST API for **DeskFlow Enterprise**, a customer-support and SLA-dispatch platform: tickets, business-hours SLA clocks, workload-aware routing, a finite-state-machine ticket lifecycle, agent collision detection, immutable audit trail, attachments, notifications and an AI assistant.

The React frontend lives in [`../deskflow-frontend`](../deskflow-frontend). Everything here is JSON over HTTP.

---

## Contents
- [Features](#features)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Quick start (local)](#quick-start-local)
- [Quick start (Docker)](#quick-start-docker)
- [Configuration](#configuration)
- [Everyday commands](#everyday-commands)
- [Testing](#testing)
- [Project structure](#project-structure)
- [Security model](#security-model)
- [Documentation](#documentation)

---

## Features

| Area | What it does |
|---|---|
| **Accounts** | Self-signup (customers only) with emailed one-time code; sign-in, password reset by code, optional TOTP two-factor with recovery codes; admins invite staff by email (they choose their own password) or create them; deactivate or erase users; change password; list and revoke signed-in devices |
| **Tickets** | Create, list/filter/search, reply (public or internal note), priority, assignment, tags, watchers, @mentions, merge duplicates, CSV export |
| **SLA engine** | First-response and resolution targets per plan and priority, counted in each department's **business hours and timezone**, skipping weekends and holidays; clocks pause while waiting on the customer; breach flagging and 15-minute warnings |
| **Routing** | Auto-assigns to the least-loaded available agent in the department (ratio of active tickets to capacity, round-robin tiebreak); row locks prevent double assignment; leads are alerted when everyone is full |
| **Lifecycle** | Finite state machine (open, in progress, pending customer, resolved, closed) with role-aware transitions |
| **Organizations** | Customer companies (domain, SLA plan, active): customers auto-join by email domain; "company admin" customers see and answer their whole company's tickets |
| **Workflow tools** | Saved views, bulk status/priority/assign, per-department ticket forms (custom fields), no-code automation rules (on create, on customer reply, after N idle hours) |
| **AI extras** | Triage suggestions (priority/tags, accept or dismiss), upset/urgent flags (work without AI too), duplicate suggestions, meaning-based help search, reply translation (emails, links and numbers masked) |
| **Integrations** | Slack, Microsoft Teams and signed generic webhooks for new tickets, assignments, status changes, customer replies, SLA warnings/breaches and low ratings; SSRF-safe, encrypted at rest, retried, auto-disabled after repeated failures |
| **Realtime and push** | Laravel Reverb WebSockets (tiny "changed" pings, re-fetched through the authorised API), browser push, installable PWA |
| **Collaboration** | Live agent presence/typing warnings, internal notes hidden from customers |
| **Attachments** | Browser uploads directly to Cloudinary using signed parameters; files are private and served by short-lived links |
| **Notifications** | In-app bell and email (queued) for replies, assignments, SLA warnings, mentions; customers are asked to rate resolved tickets |
| **Help center** | Public knowledge base with categories, search (falls back to AI by meaning), "was this helpful?" votes; staff editor with usage analytics and unanswered searches |
| **AI assistant** | Floating chat widget (guests: help questions only; customers: also their own tickets; staff: ticket figures by role, namely admins the whole app, leads their department, agents only their assigned tickets) and staff "Draft reply" / "Summarize" tools, via Groq (any OpenAI-compatible API works) |
| **Inbound email** | Customers can reply by email; new emails from known customers open tickets |
| **Reports** | SLA overview, trends, agent workload, satisfaction per day/agent with low-rating alerts, CSV export, global audit log |
| **Privacy** | Customers can download their data or erase their account |
| **Profile** | Everyone can edit their display name and upload a profile photo (direct to Cloudinary, verified server-side); photos appear in conversations |
| **Branded email** | Every email uses one branded layout with the logo embedded in the message (works offline and in every mail client) |

## Tech stack

PHP 8.2+ (runs on 8.3 in Docker) · Laravel 11 · Laravel Sanctum (bearer tokens) · PostgreSQL (Supabase) or MySQL · cache and queue in the database (Redis optional) · Cloudinary (files) · SMTP (email) · Groq (AI) · PHPUnit.

## Architecture

```
Controllers (thin, validate + authorize)
   └─ Services  — SlaCalculatorService, TicketRoutingService, TicketStateMachineService,
                  TicketCreationService, TicketNotifier, AgentPresenceService, AuditLoggerService,
                  Ai\AssistantService, Ai\TicketAiService, CloudinaryService, InboundEmailService ...
        └─ Eloquent models + Policies (TicketPolicy) + Form Requests + API Resources
Queue (Redis): notifications, SLA warning jobs        Scheduler: sla:check-breaches every minute
```

Design rules worth knowing:
- **Authorization lives in code.** Roles via route middleware, ticket access via `TicketPolicy`.
- **The audit table is append-only.** The model refuses updates and deletes.
- **The AI never touches account data.** It chooses an *intent*; our code fetches the data, scoped to the signed-in user.
- **No secrets reach the browser.** File uploads use signatures generated server-side.

## Requirements

- PHP 8.2+ with `pdo_pgsql` (or `pdo_mysql`), `redis` (phpredis), `mbstring`, `intl`, `bcmath`, `curl`, `zip`
- Composer 2
- PostgreSQL 14+ or MySQL 8. Redis is **optional**: by default cache and queue use database tables
- Node is **not** needed for the API

## Quick start (local)

```bash
cd deskflow-api
composer install
cp .env.example .env
php artisan key:generate

# edit .env: database (DB_*), FRONTEND_URL, optional CLOUDINARY_* and GROQ_API_KEY

php artisan migrate
php artisan db:seed                       # DEMO data only (password123 for every account)
php artisan serve                         # http://127.0.0.1:8000

# in two more terminals
php artisan queue:work                    # emails + SLA warning jobs
php artisan schedule:work                 # flags SLA breaches every minute
```

Demo accounts created by the seeder (local use only):

| Role | Email | Password |
|---|---|---|
| Admin | `admin@deskflow.com` | `password123` |
| Lead | `lead@deskflow.com` | `password123` |
| Agent | `agent.sarah@deskflow.com` | `password123` |
| Customer | `rahul@acme.com` | `password123` |

With `MAIL_MAILER=log` (the default) emails, including signup codes, are written to `storage/logs/laravel.log`.

**Create a real admin without seeding:**
```bash
php artisan deskflow:create-admin --name="Your Name" --email=you@company.com
```

## Quick start (Docker)

```bash
cp .env.docker.example .env.docker        # set APP_KEY (php artisan key:generate --show)
docker compose --env-file .env.docker up --build
```
Starts the API (http://localhost:8000), queue worker, scheduler, Postgres, Redis and the frontend (http://localhost:5173).

The single image supports several roles chosen by `CONTAINER_ROLE` (`web`, `worker`, `scheduler`, `cron`, `migrate`). It listens on `$PORT` (default 10000). Details: [`docs/RENDER_DEPLOY.md`](docs/RENDER_DEPLOY.md).

## Configuration

Copy `.env.example` (local) or see `.env.production.example` (annotated production values). Key settings:

| Variable | Purpose |
|---|---|
| `APP_KEY`, `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` | identity, email links, which browser origins may call the API |
| `DB_CONNECTION`, `DB_URL` | database (use the Supabase **session pooler, port 5432**) |
| `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (default) or `redis` |
| `MAIL_*` | SMTP. Required in production or nobody can verify their email |
| `CLOUDINARY_*` | attachments |
| `GROQ_API_KEY`, `AI_MODEL`, `AI_DAILY_LIMIT` | AI assistant |
| `INBOUND_EMAIL_SECRET`, `INBOUND_EMAIL_DEFAULT_DEPARTMENT` | reply-by-email webhook (empty secret = off) |
| `SANCTUM_EXPIRATION` | login lifetime in minutes (default 480) |
| `CRON_SECRET` | enables `POST /api/v1/internal/tick` so an external pinger can run the scheduler and queue (empty = off) |
| `BROADCAST_CONNECTION`, `REVERB_*` | realtime updates (`log` = off); run `php artisan reverb:start` |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | browser push (`php artisan webpush:vapid` creates the keys) |
| `WEBHOOKS_ALLOW_HTTP` | allow plain `http://` webhook URLs (local development only) |
| `RUN_WORKER_IN_WEB`, `RUN_SCHEDULER_IN_WEB`, `RUN_MIGRATIONS` | Docker only: run the worker/scheduler/migrations inside the web container |

## Everyday commands

| Command | What it does |
|---|---|
| `php artisan migrate` | apply database migrations |
| `php artisan queue:work` | process queued email and jobs (required for email) |
| `php artisan schedule:work` | run the scheduler locally |
| `php artisan sla:check-breaches` | mark overdue SLA deadlines as breached |
| `php artisan automation:run-idle` | apply "idle for N hours" automation rules (scheduled every 10 minutes) |
| `php artisan reverb:start` | start the realtime WebSocket server |
| `php artisan webpush:vapid` | create browser-push keys |
| `php artisan deskflow:create-admin` | create an administrator |
| `php artisan route:list --path=api` | list endpoints |
| `php artisan config:clear` | after changing `.env` |

## Testing

```bash
cp .env.testing.example .env.testing      # local MySQL credentials (git-ignored)
# CREATE DATABASE deskflow_api_testing;
php artisan test
```

- The suite uses a **local MySQL** database (`phpunit.xml` fixes host and name). External services (Groq, Cloudinary) are faked; no network calls.
- **Safety net:** `tests/TestCase.php` refuses to run unless the database host is local and the name ends in `_testing`, because `RefreshDatabase` drops every table. Never point tests at a hosted database.

## Email templates

All emails share one look (indigo header with the DeskFlow logo, panel, button, footer).

| What | Where |
|---|---|
| Layout, header (logo), footer | `resources/views/vendor/mail/html/` (`header.blade.php`, `message.blade.php`) |
| Colours and typography | `resources/views/vendor/mail/html/themes/default.css` (brand colour `#4f46e5`) |
| One template per email | `resources/views/emails/` (`otp`, `ticket-reply`, `ticket-resolved`, `staff-activity`, `unassigned`) |
| The logo | `public/images/email/logo.png` (white on transparent; shown on the indigo header) |
| Building a mail | `app/Notifications/Concerns/BrandedMail.php` embeds the logo as an inline `cid:` image |
| Escaping | `app/Support/MarkdownSafe.php` shows customer/agent text literally, so nobody can inject links into an official email |

To change the logo replace `public/images/email/logo.png`. To preview an email, render it with `(new SomeNotification(...))->toMail($user)->render()`.

## Project structure

```
app/
  Console/Commands/      CheckSlaBreaches, CreateAdmin
  Exceptions/            domain exceptions (render as 409/422 JSON)
  Http/Controllers/Api/V1/   one controller per area
  Http/Requests/         validation
  Http/Resources/        JSON shapes (role-aware)
  Jobs/                  SlaBreachWarningJob
  Models/                Eloquent models
  Notifications/         email + in-app notifications
  Policies/              TicketPolicy
  Services/              business logic (SLA, routing, FSM, AI, uploads, ...)
config/                  services.php holds Cloudinary / AI / inbound email settings
database/migrations/     schema history
database/seeders/        DEMO data (never run in production)
docker/                  nginx, php-fpm, supervisor and entrypoint for the image
docs/                    API.md, RENDER_DEPLOY.md and more
routes/api.php           all endpoints
tests/                   feature tests
```

## Security model

- Public registration can only create **customers**; role and organization are never read from the request.
- Staff accounts are created by admins (or `deskflow:create-admin` for the first one).
- Roles are enforced by middleware **and** object-level policies; customers only ever see their own tickets (company admins their organization's), agents their assigned tickets plus their department's unassigned pool, leads their department.
- Outbound webhooks accept only public https addresses; the address is re-checked on every attempt and the connection is pinned to the checked IP (no DNS rebinding). Browser-push addresses must belong to the browsers' own push services.
- Realtime pings carry no ticket text; private channels are authorised with the same policies as the API.
- OTP codes are stored hashed, expire in 10 minutes, are single-use, and lock after 5 wrong attempts. Login, OTP, assistant and AI endpoints are rate limited.
- Internal notes and audit history are never returned to customers.
- Attachments are private (Cloudinary `authenticated`); download links expire.
- Customer text is redacted (emails, phones, links) before it is sent to the AI provider; attachments are never sent.
- The Docker image runs the application as an unprivileged user.

## Documentation

| File | Contents |
|---|---|
| [`docs/API.md`](docs/API.md) | every endpoint, who may call it, throttling |
| [`docs/RENDER_DEPLOY.md`](docs/RENDER_DEPLOY.md) | deploying this Docker image on Render, step by step |
| [`../docs/DEPLOYMENT_GUIDE.md`](../docs/DEPLOYMENT_GUIDE.md) | overall deployment overview (Render + Vercel) |
| [`../docs/GO_LIVE_CHECKLIST.md`](../docs/GO_LIVE_CHECKLIST.md) | what to change before going live, email, security checklist |
| [`../docs/design/`](../docs/design) | original specification and deep-dive documents |
#   T i c k e t - S y s t e m - L a r a v e l - B a c k e n d  
 