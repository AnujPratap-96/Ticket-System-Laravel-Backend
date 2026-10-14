<p align="center">
  <h1 align="center">DeskFlow - Multi-Tenant Customer Support & Helpdesk API</h1>
  <p align="center">A Laravel 12 REST API for a multi-tenant helpdesk: many independent support companies on one installation, each with business-hours SLA clocks, workload-aware routing, a finite-state ticket lifecycle, live collaboration, automation, integrations and AI assistance.</p>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" />
  <img src="https://img.shields.io/badge/PostgreSQL%20%7C%20MySQL-4169E1?style=for-the-badge&logo=postgresql&logoColor=white" />
  <img src="https://img.shields.io/badge/Sanctum-Auth-F05340?style=for-the-badge" />
  <img src="https://img.shields.io/badge/Reverb-WebSockets-F56565?style=for-the-badge" />
  <img src="https://img.shields.io/badge/Cloudinary-Media-3448C5?style=for-the-badge&logo=cloudinary&logoColor=white" />
  <img src="https://img.shields.io/badge/Groq-AI-F55036?style=for-the-badge" />
</p>

> **How it is built is described in [`../ARCHITECTURE.md`](../ARCHITECTURE.md)** (diagrams for the request flow, multi-tenancy, SLA engine, routing, automation, realtime and the data model). This file is the practical guide: features, setup, configuration and commands. Every endpoint is listed in [`docs/API.md`](docs/API.md).

---

## Table of Contents

- [Overview](#overview)
- [Feature Set](#feature-set)
  - [1. Multi-Tenancy (workspaces) and the Platform Owner](#1-multi-tenancy-workspaces-and-the-platform-owner)
  - [2. Authentication, Sessions & Two-Factor](#2-authentication-sessions--two-factor)
  - [3. Roles and Ticket Visibility](#3-roles-and-ticket-visibility)
  - [4. Ticket Lifecycle](#4-ticket-lifecycle)
  - [5. Business-Hours SLA Engine](#5-business-hours-sla-engine)
  - [6. Workload-Aware Routing](#6-workload-aware-routing)
  - [7. Collaboration](#7-collaboration)
  - [8. Customer Organizations, Plans and Limits](#8-customer-organizations-plans-and-limits)
  - [9. Automation Rules](#9-automation-rules)
  - [10. AI Features](#10-ai-features)
  - [11. Help Center](#11-help-center)
  - [12. Integrations, Realtime and Push](#12-integrations-realtime-and-push)
  - [13. Attachments and Email](#13-attachments-and-email)
  - [14. Analytics and Reporting](#14-analytics-and-reporting)
  - [15. Audit Trail and Privacy](#15-audit-trail-and-privacy)
- [Tech Stack](#tech-stack)
- [Architecture at a Glance](#architecture-at-a-glance)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Demo Accounts](#demo-accounts)
- [Docker Deployment](#docker-deployment)
- [Environment Variables](#environment-variables)
- [Artisan Commands and Scheduler](#artisan-commands-and-scheduler)
- [Testing](#testing)
- [Known Limits](#known-limits)

---

## Overview

DeskFlow serves **many independent support companies ("workspaces" / tenants) from one installation**. Each workspace has its own administrators, leads, agents, departments, customers, tickets, SLA plans, help articles, webhooks, automation rules, holidays and settings, and can never see another workspace's data. A **platform owner** (outside every workspace) creates and suspends workspaces and sees counts only.

Inside a workspace the API provides SLA tracking against department business hours, automatic least-loaded routing with row locks, a role-aware ticket state machine, live collaboration, a no-code automation engine, Slack/Teams/signed webhooks, browser push, AI triage and an AI assistant, and an append-only audit trail.

The React client is in [`../deskflow-frontend`](../deskflow-frontend). Everything is JSON over HTTP, with WebSocket pings (Laravel Reverb) as an optional extra.

---

## Feature Set

### Who belongs where

There are three levels, and every account sits at exactly one of them.

```
PLATFORM            the DeskFlow installation itself (one platform owner, platform@deskflow.com)
│                   creates, suspends and deletes workspaces; sees counts only, never tickets
│
├── WORKSPACE "TechCorp Support"     one company that RUNS a helpdesk (a tenant)
│   ├── STAFF                        admin, leads, agents: they work for the workspace and have no organization
│   └── CUSTOMER COMPANIES           "organizations": the workspace's clients (Acme, Globex ...)
│        └── customers               people from those companies who file tickets
│
└── WORKSPACE "ShopEase Support"     a second company, completely separate (its own staff, clients, tickets)
```

Think of a shopping mall: the **platform** is the mall owner (rents out shops, never looks inside), a **workspace** is one shop, **staff** are the shop's employees (the admin is the manager), **organizations** are the shop's business customers, and a **ticket** is a customer's request. A workspace admin belongs to their workspace, not to the platform and not to any customer company, so their "organization" is empty. The platform owner is a separate account that belongs to no workspace.

### 1. Multi-Tenancy (workspaces) and the Platform Owner

- **Isolation by construction.** Every workspace-owned model uses the `BelongsToTenant` trait: a global query scope adds `tenant_id = <current workspace>` to every query and new rows are stamped automatically (`app/Tenancy`). Another workspace's ids simply return **404**.
- **Which workspace?** A signed-in person's workspace is always their own; nothing in the request can change it. Before sign-in it comes from the address (`acme.yourdomain.com`, when `TENANCY_BASE_DOMAIN` is set), the `X-Tenant` header, or `?tenant=`. With exactly one workspace and no hint, that workspace is used.
- **Everything is scoped, not only queries:** validation rules (`TenantRule`), one-time codes, caches, AI budgets, settings, realtime channels, emailed links and background jobs (scheduled automation runs once per workspace; SLA warnings, AI analysis and webhooks run inside the ticket's own workspace).
- **Per-workspace uniqueness.** The same email, department slug, organization domain, article slug or holiday date can exist in two workspaces.
- **Platform owner (`super_admin`).** Belongs to no workspace and signs in at `POST /platform/login`. The console creates, renames and suspends workspaces and shows **counts only**: it can never open a workspace's tickets or people, and a workspace admin cannot reach platform routes.
- **Workspace lifecycle.** A company can create its own workspace at `/start` (emailed code), or the platform owner creates one and invites its first administrator. Suspending a workspace locks everyone in it at once.
- **Proof.** `tests/Feature/TenantIsolationTest.php` builds two workspaces and checks tickets, users, organizations, departments, articles, webhooks, rules, analytics, exports, sign-in, codes, realtime channels and scheduled jobs for leaks. With the scope switched off, 12 of its 20 tests fail.

### 2. Authentication, Sessions & Two-Factor

| Flow | Mechanism | Notes |
|---|---|---|
| Customer sign-up | Emailed 6-digit code | Codes are hashed, valid 10 minutes, single use, locked after 5 wrong tries |
| Password reset | Emailed code | Signs out every device |
| Staff invitation | Single-use link (token stored hashed, 7 days) | The invitee chooses their own password |
| API auth | Laravel Sanctum bearer tokens | Lifetime `SANCTUM_EXPIRATION` (default 480 min); at most 10 signed-in devices per account |
| Two-factor | TOTP (RFC 6238) | Authenticator app plus 8 one-time recovery codes |
| Sessions | Device list | Each token is named like "Chrome on Linux · IP"; revoke one, or all others |
| Password change | Needs the current password | Signs out other devices and emails a security notice |
| Account removal | Admin: delete or suspend | No history: deleted. Has history: suspended (name kept) and reactivatable. **Erasure** (`DELETE /me`, or admin on request) anonymises the person and removes a customer's own text |

### 3. Roles and Ticket Visibility

| Role | Sees |
|---|---|
| `admin` | Every ticket in their workspace |
| `lead` | Their department's tickets, plus tickets they watch |
| `agent` | Tickets assigned to them, their department's **unassigned** pool, and tickets they watch (an @mention adds you as a watcher) |
| `customer` | Their own tickets; a **company admin** customer also sees their organization's |
| `super_admin` | No tickets at all (platform routes only) |

The rules live in one place (`Ticket::scopeVisibleTo`, mirrored by `TicketPolicy::view`). Internal notes and audit history are never returned to customers.

### 4. Ticket Lifecycle

- **States:** `open`, `in_progress`, `pending_customer`, `resolved`, `closed`.
- **Staff transitions:** open → in_progress/closed; in_progress → pending_customer/resolved/closed; pending_customer → in_progress/resolved/closed; resolved → in_progress/closed. `closed` is locked (only an admin may reopen).
- **Customer transitions** (own ticket): close it from any open state, or move `pending_customer`/`resolved` back to `in_progress` (reopen/reply).
- A customer reply to a `pending_customer` or `resolved` ticket reopens it automatically. A first public staff reply moves `open` to `in_progress` and satisfies the first-response SLA.
- **Bulk actions** (status, priority, assign) on up to 50 tickets with per-ticket success/failure reporting; **saved views**; **per-department ticket forms** (custom fields); **tags**.
- **Merge** (lead/admin, same customer): messages move to the surviving ticket, the duplicate's original request is kept as an internal note, its clocks stop and it is closed with a pointer.
- **Cross-department assignment** by an admin moves the ticket to the agent's department, recalculates its SLA clocks for that department's hours, writes an audit entry and tells the new department's leads.

### 5. Business-Hours SLA Engine

- Targets per **plan tier** (`standard`, `silver`, `gold`, `platinum`) and **priority**: first response and resolution, counted in the **department's business hours and timezone**, skipping weekends and the workspace's holidays.
- The resolution clock **pauses** while the ticket waits on the customer and resumes after; reopening restarts it.
- `sla:check-breaches` runs every minute and flags overdue, unpaused deadlines; a warning job fires **15 minutes** before a target.
- Changing priority or department recalculates open clocks; fulfilled ones are never reset.

### 6. Workload-Aware Routing

New tickets go to the available agent or lead of the department with the **lowest active/capacity ratio** (round-robin tiebreak on last assignment). Candidate rows are locked (`SELECT … FOR UPDATE`) so concurrent bursts never double-assign. If everyone is at capacity the ticket stays unassigned and the department's leads are alerted. Manual "claim" races are resolved with a `409`.

### 7. Collaboration

- **Presence and collision warning:** a 15-second heartbeat shows who is viewing or typing; sending while someone else is typing asks for confirmation.
- **Internal notes** (staff only), **watchers**, **@mentions** (mentioned teammates gain access), **canned replies** with `{{customer_name}}`, `{{agent_name}}`, `{{agent_title}}`, `{{signature}}`.
- **Replies are written in a safe Markdown subset** (bold, italic, links, lists, quotes, code) rendered by the server; raw HTML is never rendered and images are not shown. A per-person **signature** (name and job title) can be added automatically.

### 8. Customer Organizations, Plans and Limits

An **organization** is one of a workspace's own client companies (a B2B account), not a tenant.

- **Domain auto-mapping:** customers who sign up with an `@acme.com` email join the matching organization. **Company admins** can see and reply to all of their company's tickets.
- **Plan tier** sets SLA targets. Deleting an organization unlinks its people; if it has tickets you must choose another organization to move them to.
- **Usage limits** (`config/plans.php`): tickets per month, user accounts, open tickets per person. Each organization can override them (empty = plan default, `0` = unlimited). Hitting one returns `422` with `code: quota_exceeded`. Admins see everything on **Manage → Usage and limits**.
- **Abuse protection:** per-person rate limits (5 tickets/min and 30/hour, 20 replies/min), 10 devices per account, unused logins pruned after 30 days, caps on webhooks, automation rules and saved replies.

### 9. Automation Rules

No-code rules, applied in creation order. **Triggers:** ticket created, customer replied, ticket idle for N hours (checked every 10 minutes). **Conditions** (all must match): title/description contains or not, priority, status, department, channel, customer plan, has-owner. **Actions:** set priority, add tag, assign to an agent, set status, add an internal note. A rule's own changes never trigger other rules; a failing action is logged and skipped, so automation can never block filing a ticket. Each run is recorded (`automation_runs`).

### 10. AI Features

Groq (or any OpenAI-compatible API). All AI is optional, has a per-workspace daily budget and an admin kill switch, and personal data (emails, links, numbers) is masked before text leaves the server.

- **Assistant widget:** guests get help-centre answers; customers also their own tickets; staff ticket figures by role. The model only picks an *intent*; the backend runs the authorised query.
- **Triage on new tickets:** suggested priority, tags and department (accept or dismiss, never auto-applied), plus **upset** and **urgent** flags that also work without AI from word cues.
- **Draft reply**, **summary**, **reply translation**, **duplicate suggestions** (word overlap, no AI needed), and **meaning-based help search** as a fallback when keywords find nothing.

### 11. Help Center

Public per-workspace articles (plain text) with categories, search, "was this helpful?" votes, view counts, and a list of searches that found nothing. Staff editor with analytics.

### 12. Integrations, Realtime and Push

- **Slack, Microsoft Teams and signed generic webhooks** for new tickets, assignments, status changes, customer replies, SLA warnings/breaches and low ratings. Messages contain ticket number, title, status, priority and a link, never the conversation. Generic webhooks are signed (`X-DeskFlow-Signature`, HMAC-SHA256), retried (30 s, 2 min, 10 min) and switched off after 20 consecutive failures.
- **SSRF protection:** only public `https` addresses; the host is re-resolved on every attempt and the connection is pinned to the checked IP.
- **Realtime (Laravel Reverb):** tiny "this ticket changed" pings on private channels `user.{id}`, `ticket.{id}` and `ticket.{id}.staff`. They carry no content; browsers re-fetch through the authorised API. Optional: without it, screens poll.
- **Browser push and PWA:** Web Push with VAPID keys (`php artisan webpush:vapid`); push endpoints must belong to the browsers' own push services. Customers' lock-screen text is generic.

### 13. Attachments and Email

- **Cloudinary direct uploads:** the API signs the upload, the browser sends the file straight to Cloudinary (private/authenticated assets, expiring download links, server-side verification before linking).
- **Branded email** (one layout, embedded logo, user text always escaped): sign-up codes, replies, assignments, SLA warnings, invitations and security notices; queued unless `QUEUE_OTP=false` for codes.
- **Inbound email:** `POST /webhooks/inbound-email` (shared secret, `?tenant=` to pick the workspace). A `[TICK-YYYY-NNNNN]` tag in the subject threads a reply onto its ticket; known customers' new emails open tickets; message ids are de-duplicated and SPF failures are rejected.

### 14. Analytics and Reporting

SLA overview (compliance, breaches, at-risk), ticket trends, agent workload and CSAT, satisfaction per day and per agent with low-rating (1-2 stars) alerts to the department's leads and admins, usage against plan limits, a global audit log, and a **tickets CSV export**.

### 15. Audit Trail and Privacy

- **Append-only audit log** (`TicketAudit`): updating or deleting an entry throws; every status change, assignment, priority/department change and administrative action is recorded.
- **Data export** (`GET /me/export`) and **right to erasure** (`DELETE /me`: identity anonymised, the customer's own messages and descriptions removed, ticket records kept for metrics).

---

## Tech Stack

| Layer | Technologies |
|---|---|
| Framework | PHP 8.2+, Laravel 12 |
| Auth | Laravel Sanctum bearer tokens, TOTP two-factor |
| Database | PostgreSQL (Supabase session pooler) or MySQL 8; queue and cache default to database tables, Redis optional |
| Realtime | Laravel Reverb (WebSockets) |
| Push | `minishlink/web-push` (VAPID) |
| Media | Cloudinary (signed direct uploads) |
| Mail | Any SMTP provider, Markdown mail with a branded theme |
| AI | Groq via the OpenAI-compatible API (model configurable) |
| Tests | PHPUnit feature tests (local MySQL), Playwright browser tests (in the frontend) |

---

## Architecture at a Glance

```
Clients (web app, webhooks, mail provider)
        │
  Middleware: ResetTenantContext → Authenticate (Sanctum) → ResolveTenant → route-model binding
        │
  Controllers (thin) + FormRequests (tenant-aware rules) + API Resources
        │
  Services: TicketCreationService, TicketStateMachineService, TicketRoutingService, SlaCalculatorService,
            AutomationEngine, PlanLimits, TicketNotifier, TicketEvents, Ai/*, TenantProvisioner ...
        │
  Eloquent models (BelongsToTenant scope) + TicketPolicy
        │
  PostgreSQL / MySQL   ·   queue + scheduler workers   ·   Reverb (optional)
```

See [`../ARCHITECTURE.md`](../ARCHITECTURE.md) for sequence diagrams and the data model.

---

## Project Structure

```
deskflow-api/
├── app/
│   ├── Console/Commands/        # CheckSlaBreaches, RunIdleAutomations, CreateAdmin, CreatePlatformAdmin, GenerateVapidKeys
│   ├── Enums/                   # UserRole, TicketStatus, TicketPriority, SlaTier, SlaMetricType, TicketChannel
│   ├── Events/                  # TicketChanged, UserPinged (realtime pings)
│   ├── Exceptions/              # AgentCollision, AgentWorkloadExceeded, InvalidTicketStateTransition, QuotaExceeded
│   ├── Http/
│   │   ├── Controllers/Api/V1/  # REST controllers (+ Platform/ for the platform owner and workspace sign-up)
│   │   ├── Middleware/          # ResetTenantContext, ResolveTenant, EnsureUserHasRole
│   │   ├── Requests/            # FormRequest validation
│   │   └── Resources/           # JSON resources
│   ├── Jobs/                    # AnalyzeTicketJob, DeliverWebhookJob, SlaBreachWarningJob
│   ├── Models/                  # Eloquent models (tenant-scoped via BelongsToTenant)
│   ├── Notifications/           # Branded mail/database/push notifications
│   ├── Policies/                # TicketPolicy
│   ├── Services/                # Domain services; Services/Ai holds the AI subsystem
│   ├── Support/                 # SafeUrl (SSRF guard), RichText, MarkdownSafe, TicketForm, DeviceLabel
│   └── Tenancy/                 # TenantContext, TenantScope, BelongsToTenant, TenantRule
├── config/                      # plans.php (limits), tenancy.php, services.php, broadcasting.php, reverb.php ...
├── database/{migrations,seeders}/
├── docker/                      # entrypoint, nginx, php-fpm, supervisord
├── docs/                        # API.md (endpoints), RENDER_DEPLOY.md
├── resources/views/emails/      # Branded email templates
├── routes/                      # api.php, platform.php, channels.php, console.php (scheduler)
└── tests/Feature/               # 280+ tests
```

---

## Getting Started

### Prerequisites

- PHP 8.2+ with `pdo_pgsql` (or `pdo_mysql`), `mbstring`, `intl`, `bcmath` (or `gmp`), `curl`, `zip`, `openssl`
- Composer 2+
- PostgreSQL 14+ or MySQL 8

### Local Setup

```bash
cd deskflow-api
composer install
cp .env.example .env
php artisan key:generate
# set DB_* in .env (and FRONTEND_URL / CORS_ALLOWED_ORIGINS if the web app is not on :5173)
php artisan migrate
php artisan db:seed          # two demo workspaces + the platform owner
php artisan serve            # http://127.0.0.1:8000
```

Optional, in other terminals:

```bash
php artisan queue:work       # emails, SLA warnings, webhooks, AI analysis
php artisan schedule:work    # SLA breach checks, idle automation, token clean-up
php artisan reverb:start     # live updates (set BROADCAST_CONNECTION=reverb and the REVERB_* values)
```

---

## Demo Accounts

`php artisan db:seed` creates two independent workspaces and a platform owner. Every demo account gets the password in `DEMO_PASSWORD` (set it in `.env` before seeding; if unset, a random one is printed once). Never seed demo accounts on a public deployment.

| Workspace | Role | Email |
|---|---|---|
| `techcorp` (TechCorp Support) | Admin | `admin@deskflow.com` |
| | Team lead | `lead@deskflow.com` |
| | Agent | `agent.sarah@deskflow.com` |
| | Customer | `rahul@acme.com` |
| `shopease` (ShopEase Support) | Admin | `admin@shopease.com` |
| | Agent | `agent@shopease.com` |
| | Customer | `rahul@acme.com` (same email, a different person and different tickets) |
| Platform | Platform owner | `platform@deskflow.com` |

Create real accounts without the demo data:

```bash
php artisan deskflow:create-platform-admin
php artisan deskflow:create-admin --tenant=<workspace-slug>
```

---

## Docker Deployment

One image, several roles (`CONTAINER_ROLE`):

| Role | Runs | Purpose |
|---|---|---|
| `web` (default) | nginx + php-fpm on `$PORT` | The API (can also run the worker/scheduler with `RUN_WORKER_IN_WEB`, `RUN_SCHEDULER_IN_WEB`) |
| `worker` | `queue:work` | Emails, webhooks, SLA warnings, AI analysis |
| `scheduler` | `schedule:work` | Continuous scheduler |
| `cron` | `schedule:run` once | For platforms with cron jobs |
| `reverb` | `reverb:start` | Optional realtime WebSocket server |
| `migrate` | `migrate --force` | One-off migrations |

```bash
cp .env.docker.example .env.docker
docker compose --env-file .env.docker up --build
```

Render deployment: [`docs/RENDER_DEPLOY.md`](docs/RENDER_DEPLOY.md), with the blueprints `render.yaml` (web, worker, scheduler, optional realtime) and `render.free.yaml` (one free service) next to this file. The image was not built in the environment where this was written; try it locally first.

---

## Environment Variables

The annotated lists are `.env.example` (local) and `.env.production.example`. The ones you will touch:

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY`, `APP_URL`, `FRONTEND_URL` | | Identity and the base of emailed links |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Comma-separated browser origins |
| `DB_CONNECTION`, `DB_URL` | `mysql` | Use the Supabase **session pooler (port 5432)** for PostgreSQL |
| `DB_PERSISTENT` | `false` | Keep the database connection open between requests (saves ~0.2 s over the internet) |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database` | `database` or `redis` |
| `QUEUE_OTP` | `false` | `true` sends sign-up/reset codes through the queue worker (use in production) |
| `MAIL_*` | `log` | SMTP; required in production |
| `SANCTUM_EXPIRATION` | `480` | Login lifetime in minutes (`null` = never; local development only) |
| `TENANCY_BASE_DOMAIN` | unset | Wildcard domain for workspaces; unset = `X-Tenant` / `?tenant=` |
| `TENANCY_SINGLE_FALLBACK` | `true` | With exactly one workspace and no hint, use it |
| `TENANCY_SELF_SIGNUP` | `true` | Anyone may create a workspace at `/start` |
| `CLOUDINARY_*` | unset | Attachments and profile photos |
| `GROQ_API_KEY`, `AI_MODEL`, `AI_DAILY_LIMIT` | unset / `800` | AI features and the per-workspace daily budget |
| `TURNSTILE_SECRET` | unset | Cloudflare Turnstile human check on sign-up and forgot-password (empty = off; the web app needs `VITE_TURNSTILE_SITE_KEY`) |
| `SECURITY_CHECK_BREACHED` | on in production | Refuse passwords found in known breaches (k-anonymity lookup) |
| `BACKUP_DISK`, `OPS_ALERT_WEBHOOK` | `local` / unset | Where nightly database backups go; Slack/Teams-style webhook told when the health check fails |
| `BROADCAST_CONNECTION`, `REVERB_*` | `log` | Realtime (`reverb`) or off (`log`) |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | unset | Browser push |
| `WEBHOOKS_ALLOW_HTTP` | `false` | Allow plain `http://` webhook URLs (development only) |
| `CRON_SECRET` | unset | Enables `POST /api/v1/internal/tick` for an external pinger |

---

## Artisan Commands and Scheduler

| Command | Description |
|---|---|
| `php artisan migrate`, `db:seed` | Schema and demo data |
| `php artisan deskflow:create-admin --tenant=<slug>` | Create a workspace administrator |
| `php artisan deskflow:create-platform-admin` | Create the platform owner |
| `php artisan db:backup --keep=14` | Gzip dump (mysqldump / pg_dump) to `BACKUP_DISK`, old ones pruned (scheduled 02:30) |
| `php artisan ops:health --alert` | Checks database, scheduler heartbeat, failed jobs and backup age (every 15 minutes; same data at `GET /platform/health`) |
| `php artisan reports:weekly` | Weekly summary email to each workspace's admins (Mondays 07:00) |
| `php artisan sla:check-breaches` | Flag overdue SLA deadlines (scheduled every minute) |
| `php artisan automation:run-idle` | Apply "idle for N hours" rules, once per workspace (every 10 minutes) |
| `php artisan sanctum:prune-expired --hours=24` | Remove expired logins (scheduled daily with unused-for-30-days tokens) |
| `php artisan webpush:vapid` | Generate browser-push keys |
| `php artisan reverb:start` | Realtime server |
| `php artisan queue:work`, `schedule:work` | Workers |

---

## Testing

```bash
cp .env.testing.example .env.testing     # local MySQL credentials (git-ignored)
# CREATE DATABASE deskflow_api_testing;
php artisan test                          # 280+ feature tests; Groq, Cloudinary and webhooks are faked
```

`phpunit.xml` pins the host and database name, and `tests/TestCase.php` refuses to run unless the database is local and its name ends in `_testing`, because `RefreshDatabase` drops every table. Never point the tests at a hosted database. Browser tests live in `../deskflow-frontend/e2e`.

---

## Known Limits

- The Docker image and Render blueprint have not been built or deployed from here.
- Plan limits apply to a workspace's customer organizations; there is no billing for the workspaces themselves yet.
- Time tracking is per ticket (staff only, `/tickets/{id}/time`); there is no timesheet report yet.
- Ticket numbers (`TICK-YYYY-NNNNN`) come from a global sequence, so a workspace can infer overall volume from its own numbers.
- Not built: error monitoring (Sentry), billing for the workspaces themselves, single sign-on (Google/Microsoft), public API keys with an OpenAPI document, CC on tickets, skills-based routing. The weekly summary has no per-person opt-out yet.
