<p align="center">
  <h1 align="center">DeskFlow - Enterprise Customer Support & Helpdesk Platform</h1>
  <p align="center">A high-performance Laravel 11 REST API powering an enterprise helpdesk with business-hours SLA clocks, workload-aware routing, finite-state ticket lifecycle, real-time collaboration, and AI triage.</p>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" />
  <img src="https://img.shields.io/badge/PostgreSQL-4169E1?style=for-the-badge&logo=postgresql&logoColor=white" />
  <img src="https://img.shields.io/badge/Sanctum-Auth-F05340?style=for-the-badge" />
  <img src="https://img.shields.io/badge/Reverb-WebSockets-F56565?style=for-the-badge" />
  <img src="https://img.shields.io/badge/Cloudinary-Media-3448C5?style=for-the-badge&logo=cloudinary&logoColor=white" />
  <img src="https://img.shields.io/badge/Groq-AI-F55036?style=for-the-badge" />
</p>

---

## Table of Contents

- [Overview](#overview)
- [Feature Set](#feature-set)
  - [1. Authentication, Sessions & Two-Factor (2FA)](#1-authentication-sessions--two-factor-2fa)
  - [2. Ticket Lifecycle & Finite State Machine](#2-ticket-lifecycle--finite-state-machine)
  - [3. Multi-Tier Business-Hours SLA Engine](#3-multi-tier-business-hours-sla-engine)
  - [4. Workload-Aware Intelligent Routing](#4-workload-aware-intelligent-routing)
  - [5. Real-Time Collaboration & Collision Detection](#5-real-time-collaboration--collision-detection)
  - [6. AI Assistant, Triage & Reply Generation](#6-ai-assistant-triage--reply-generation)
  - [7. Automation Engine & Custom Trigger Rules](#7-automation-engine--custom-trigger-rules)
  - [8. Multi-Tenant Organizations & Access Control](#8-multi-tenant-organizations--access-control)
  - [9. Direct-to-Cloudinary Secure Attachments](#9-direct-to-cloudinary-secure-attachments)
  - [10. Multi-Channel Support & Inbound Email](#10-multi-channel-support--inbound-email)
  - [11. Knowledge Base & Help Center Management](#11-knowledge-base--help-center-management)
  - [12. Analytics, CSAT Ratings & Reporting](#12-analytics-csat-ratings--reporting)
  - [13. Audit Trails, GDPR Compliance & Privacy](#13-audit-trails-gdpr-compliance--privacy)
- [Tech Stack](#tech-stack)
- [Architecture & Design Decisions](#architecture--design-decisions)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Demo Accounts](#demo-accounts)
- [Docker Deployment](#docker-deployment)
- [Environment Variables](#environment-variables)
- [Available Artisan Commands](#available-artisan-commands)

---

## Overview

**DeskFlow API** is an enterprise-grade RESTful API built on Laravel 11 that provides a multi-role customer service and SLA dispatch infrastructure. It serves both customer-facing support portals and high-volume staff management consoles. 

The API delivers precision SLA tracking operating across department business hours, automatic workload routing with row-level locking, finite-state ticket progressions, live agent presence detection, direct Cloudinary media signing, Groq-powered AI capabilities, and immutable audit logs.

The React single-page frontend lives in [`../deskflow-frontend`](../deskflow-frontend). Everything in this repository communicates as JSON over HTTP with optional WebSocket notifications.

---

## Feature Set

### 1. Authentication, Sessions & Two-Factor (2FA)

Comprehensive identity and session management:

| Flow | Mechanism | Description |
|---|---|---|
| Self-Registration | Email OTP | Customers verify registration with a 6-digit one-time password delivered by email |
| Password Reset | Email OTP | Rate-limited OTP verification flow invalidating all active sessions on change |
| Staff Invites | Signed Token | Admins invite staff via time-limited token links; invitees establish their own credentials |
| Token Auth | Laravel Sanctum | Bearer personal access tokens stored securely on clients |
| Two-Factor Auth (2FA) | TOTP (RFC 6238) | Authenticator app enrolment (Google Authenticator, 1Password) with 8 backup recovery codes |
| Device Management | Session Auditing | List all active sessions and revoke individual devices or revoke other sessions remotely |
| User Administration | Soft-Disable & Erase | Admins can deactivate accounts, resend invites, or permanently erase user records |

---

### 2. Ticket Lifecycle & Finite State Machine

Strict state machine enforcing deterministic status transitions across roles:

- **States:** `open`, `in_progress`, `pending_customer`, `resolved`, `closed`
- **Role Guards:** Customers can only transition their own tickets between `open`, `resolved`, and `closed` (reopen). Staff can transition through `in_progress` and `pending_customer`.
- **Clock Pausing:** Moving a ticket to `pending_customer` automatically pauses SLA resolution timers.
- **Bulk Operations:** Staff can perform bulk reassignment, priority updates, and status transitions on up to 50 tickets simultaneously.
- **Ticket Merging:** Merge duplicate tickets while re-parenting messages, attachments, and preserving historical reference trails.

---

### 3. Multi-Tier Business-Hours SLA Engine

Precision deadline tracking calculated against customized department work schedules:

- **Custom Schedules:** Department-specific working hours (e.g. 09:00–17:00) and timezones.
- **Holiday Calendars:** Automatically excludes corporate holidays and weekends from SLA countdown clocks.
- **Plan Tiers:** Configurable first-response and full-resolution targets mapped by priority (`low`, `medium`, `high`, `urgent`) and customer SLA plan.
- **Breach Management:** Automated minute-by-minute worker checks (`sla:check-breaches`), 15-minute advance breach warnings, and automatic lead notifications.

---

### 4. Workload-Aware Intelligent Routing

Automated ticket assignment distributing tickets evenly to prevent agent burnout:

- **Capacity Scoring:** Evaluates active tickets against agent maximum capacity ratios.
- **Round-Robin Tiebreak:** Distributes work among tied agents fairly.
- **Concurrency Protection:** Uses database row locks (`SELECT ... FOR UPDATE`) to guarantee zero duplicate assignments during concurrent ticket bursts.
- **Lead Escalation:** Automatically alerts department leads when all available agents reach maximum capacity.

---

### 5. Real-Time Collaboration & Collision Detection

Prevents duplicate agent replies and enhances team visibility:

- **Collision Detection:** 15-second agent presence heartbeat alerts colleagues in real-time when another agent is viewing or typing a reply to a ticket.
- **Private Staff Notes:** Internal notes hidden from customer portals, styled distinctly for staff-only collaboration.
- **Watchers & Mentions:** Agents can watch tickets for live alerts and mention teammates using `@agent` syntax.
- **Real-Time Streaming:** Broadcasts lightweight change events via Laravel Reverb over WebSockets to trigger instantaneous client UI re-fetches.

---

### 6. AI Assistant, Triage & Reply Generation

Integrated LLM workflows powered by Groq (compatible with any OpenAI-compatible provider):

- **Smart Triage:** Auto-analyzes incoming tickets to suggest priority and tag classifications.
- **Draft Reply Assistant:** Generates contextual, empathetic response drafts grounded in previous ticket messages and knowledge base articles.
- **Ticket Summarization:** Generates instant executive summaries of lengthy ticket histories.
- **Multilingual Translation:** Translates international customer messages with PII redaction masking emails, URLs, and phone numbers before API dispatch.
- **Support Chatbot:** Floating widget answering customer questions using published knowledge base articles.
- **Budget Protection:** Configurable daily token ceilings and usage limits prevent unintended API overages.

---

### 7. Automation Engine & Custom Trigger Rules

Configurable no-code automation engine:

- **Event Triggers:** Fires on `ticket.created`, `customer.replied`, and `ticket.idle_hours`.
- **Conditional Logic:** Matches against priority, department, channel, tags, and elapsed idle duration.
- **Automated Actions:** Auto-assigns agents, adds tags, changes priority, sends webhook alerts, or posts automated canned messages.
- **Run Tracking:** Complete execution logs recording trigger evaluations and action outputs.

---

### 8. Multi-Tenant Organizations & Access Control

B2B corporate support capabilities:

- **Domain Auto-Mapping:** Customers signing up with `@acme.com` automatically attach to the matching corporate Organization.
- **Company Admins:** Designated customer managers can view, track, and reply to all tickets submitted across their entire company.
- **Custom SLA Policies:** Organizations can be assigned dedicated enterprise SLA agreements overriding default plans.

---

### 9. Direct-to-Cloudinary Secure Attachments

High-performance, secure media handling without server bottlenecks:

- **Direct Browser Uploads:** The API computes signed parameters (`POST /api/v1/attachments/sign`); the browser uploads binary files directly to Cloudinary.
- **Zero Server Overhead:** Server bandwidth and memory are preserved by bypassing backend multipart file handling.
- **Server Verification:** The backend verifies file signatures, mime types, and size limits before linking assets to messages.
- **Authenticated Access:** Attachments are private and served through temporary signed Cloudinary URLs.

---

### 10. Multi-Channel Support & Inbound Email

Seamless email-to-ticket conversion:

- **Inbound Webhooks:** Webhook listener (`POST /api/v1/webhooks/inbound-email`) processes incoming webhook payloads from Mailgun/Postmark/SendGrid.
- **Thread Tracking:** Matches message references and `In-Reply-To` headers to append replies to existing tickets.
- **New Ticket Generation:** Emails from recognized customers automatically spawn new tickets assigned to the default department.

---

### 11. Knowledge Base & Help Center Management

Self-service documentation platform:

- **Public Articles:** Categorized help articles with markdown formatting.
- **Helpfulness Feedback:** Upvote and downvote tracking ("Was this helpful?") to measure article quality.
- **Semantic Fallback Search:** Searches article text and falls back to AI semantic matching when standard keywords yield zero results.
- **Analytics:** Tracks article view counts and identifies unanswered customer search queries.

---

### 12. Analytics, CSAT Ratings & Reporting

Operational visibility and quality metrics:

- **CSAT Surveys:** Automated satisfaction rating requests (1 to 5 stars + comments) on ticket resolution.
- **Low-Rating Alerts:** Immediate escalation notifications sent to department leads when ratings drop below acceptable thresholds.
- **Workload Analytics:** Live dashboards displaying agent capacity, resolution times, and open queues.
- **SLA Performance:** Real-time compliance tracking, breach counts, and first-response compliance rates.
- **CSV Data Export:** Comprehensive export tooling for tickets, customers, and audit logs.

---

### 13. Audit Trails, GDPR Compliance & Privacy

Enterprise regulatory and privacy compliance:

- **Immutable Audit Trail:** Append-only log recording every status change, assignment, priority adjustment, and administrative action. Updates and deletions are prevented at the model layer.
- **GDPR Data Portability:** End-user self-service data export (`GET /api/v1/me/export`) packaging all personal data, tickets, and messages.
- **Right to Erasure:** Complete account erasure service (`DELETE /api/v1/me`) scrubbing personal identity while preserving anonymized ticket records for metrics integrity.

---

## Tech Stack

| Layer | Technologies |
|---|---|
| **Framework** | PHP 8.2+ · Laravel 11 |
| **Authentication** | Laravel Sanctum (Bearer Tokens) · TOTP 2FA |
| **Database** | PostgreSQL (Supabase session pooler) or MySQL 8 |
| **Realtime** | Laravel Reverb WebSockets · Laravel Echo protocol |
| **Storage & Media** | Cloudinary (Signed client-side uploads) |
| **Mailing** | SMTP (Brevo, Mailgun, Amazon SES) · Branded HTML templates |
| **AI Integration** | Groq API (Qwen 3.8 / Llama 3) via OpenAI-compatible endpoints |
| **Testing** | PHPUnit · Pest |

---

## Architecture & Design Decisions

```
Clients (Web / Mobile / Webhooks)
        │
   [Nginx / TLS]
        │
  [Laravel 11 Router & Sanctum Auth Middleware]
        │
  Controllers (Thin, FormRequest Validation, JSON Responses)
        │
  Services (Business Logic Layer)
   ├─ SlaCalculatorService      ── Business hours & holiday calculator
   ├─ TicketRoutingService      ── Least-loaded concurrency routing
   ├─ TicketStateMachineService ── Strict lifecycle transitions
   ├─ CloudinaryService         ── Secure upload signing & validation
   ├─ AutomationEngine          ── Rule execution engine
   └─ AssistantService          ── Groq LLM client & PII redactor
        │
  Eloquent Models + Policies (TicketPolicy)
        │
  PostgreSQL / MySQL (Database, Queue & Cache Tables)
```

1. **Authorization Resides in Code:** Routes are guarded by strict role middleware (`agent`, `lead`, `admin`), while individual ticket actions are enforced by `TicketPolicy`.
2. **Append-Only Auditing:** The `AuditLog` model rejects database updates and deletes, ensuring compliance trails cannot be tampered with.
3. **Data Scoping for AI:** The AI client never receives direct database access; it selects an intent, and backend services query the data strictly scoped to the authenticated user.
4. **Resilient Background Execution:** Critical customer actions like registration OTPs dispatch synchronously with graceful error reporting, ensuring functionality even when background workers are idle.

---

## Project Structure

```
deskflow-api/
├── app/
│   ├── Enums/                 # UserRole, TicketStatus, TicketPriority, SlaPlan
│   ├── Events/                # TicketCreated, MessageSent, UserPinged
│   ├── Http/
│   │   ├── Controllers/Api/V1/ # Clean, versioned REST controllers
│   │   ├── Middleware/        # EnsureUserHasRole, TrustProxies
│   │   ├── Requests/          # Form request validation classes
│   │   └── Resources/         # API resource transformation layer
│   ├── Models/                # Eloquent models (Ticket, User, Department, etc.)
│   ├── Notifications/         # Branded transactional notifications & OTP
│   ├── Policies/              # TicketPolicy and resource authorization
│   └── Services/              # Core domain services & AI subsystem
│       ├── Ai/                # AiClient, AssistantService, TicketAiService
│       ├── SlaCalculatorService.php
│       ├── TicketRoutingService.php
│       └── AutomationEngine.php
├── config/                    # Framework and service configuration (CORS, Sanctum, Reverb)
├── database/
│   ├── migrations/            # Complete schema migrations
│   └── seeders/               # Enterprise demo and test data seeders
├── routes/
│   ├── api.php                # V1 REST route definitions
│   └── channels.php           # Sanctum-authenticated WebSocket broadcast channels
└── docs/                      # Deployment and infrastructure guides
```

---

## Getting Started

### Prerequisites

- PHP 8.2 or 8.3 with extensions: `pdo_pgsql` (or `pdo_mysql`), `mbstring`, `intl`, `bcmath`, `curl`, `zip`
- Composer 2+
- PostgreSQL 14+ or MySQL 8 (database tables are used for queue and cache by default)

### Local Setup

1. **Clone and enter the directory:**
   ```bash
   cd deskflow/deskflow-api
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Configure environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Update database and service credentials in `.env`:**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_DATABASE=deskflow_api
   DB_USERNAME=root
   DB_PASSWORD=
   
   FRONTEND_URL=http://localhost:5173
   CORS_ALLOWED_ORIGINS=http://localhost:5173
   ```

5. **Run migrations and seed demo data:**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

6. **Start the local server:**
   ```bash
   php artisan serve
   ```
   The API will be available at `http://127.0.0.1:8000`.

7. **(Optional) Run background workers and scheduler:**
   ```bash
   # Terminal 2 - Queue worker (for background emails & SLA alerts)
   php artisan queue:work

   # Terminal 3 - Scheduler (evaluates SLA deadlines every minute)
   php artisan schedule:work
   ```

---

## Demo Accounts

When seeded with `php artisan db:seed`, the following demo accounts are created (all passwords: `password123`):

| Role | Email | Password | Access Level |
|---|---|---|---|
| **Admin** | `admin@deskflow.com` | `password123` | Full system administration, policies, webhooks, audit |
| **Team Lead** | `lead@deskflow.com` | `password123` | Department queues, analytics, team routing, KB editor |
| **Support Agent** | `agent.sarah@deskflow.com` | `password123` | Assigned tickets, ticket queues, canned replies |
| **Customer** | `rahul@acme.com` | `password123` | Customer portal, company ticket management |

To create a clean administrator without running demo seeds:
```bash
php artisan deskflow:create-admin --name="Admin User" --email=admin@yourcompany.com
```

---

## Docker Deployment

DeskFlow includes an optimized Docker image supporting multiple container roles:

```bash
cp .env.docker.example .env.docker
docker compose --env-file .env.docker up --build
```

Container roles are configured using the `CONTAINER_ROLE` environment variable:

| `CONTAINER_ROLE` | Command Executed | Purpose |
|---|---|---|
| `web` (default) | `nginx + php-fpm` | Serves HTTP REST API on `$PORT` |
| `worker` | `php artisan queue:work` | Processes queued emails and webhooks |
| `cron` | `php artisan schedule:run` | Single-run scheduler invocation |
| `scheduler` | `php artisan schedule:work` | Continuous scheduler worker |
| `migrate` | `php artisan migrate --force` | One-off migration execution |

For production hosting on Render, refer to the [Render Deployment Guide](docs/RENDER_DEPLOY.md).

---

## Environment Variables

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost:8000` | Canonical API base URL |
| `FRONTEND_URL` | `http://localhost:5173` | Frontend URL for transactional email links |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Comma-separated allowed CORS origins |
| `DB_CONNECTION` | `mysql` | Database driver (`mysql` or `pgsql`) |
| `QUEUE_CONNECTION` | `database` | Queue driver (`sync`, `database`, or `redis`) |
| `CACHE_STORE` | `database` | Cache driver (`database` or `redis`) |
| `MAIL_MAILER` | `log` | Email driver (`smtp`, `resend`, `log`) |
| `CLOUDINARY_CLOUD_NAME` | *(unset)* | Cloudinary cloud identifier |
| `CLOUDINARY_API_KEY` | *(unset)* | Cloudinary API access key |
| `CLOUDINARY_API_SECRET` | *(unset)* | Cloudinary API secret |
| `GROQ_API_KEY` | *(unset)* | Groq API key for AI assistant features |
| `AI_DAILY_LIMIT` | `800` | Maximum daily AI requests ceiling |
| `SANCTUM_EXPIRATION` | `null` | Bearer token lifetime in minutes (`null` = persistent) |
| `CRON_SECRET` | *(unset)* | Secret for `POST /api/v1/internal/tick` cron endpoint |
| `BROADCAST_CONNECTION` | `log` | Broadcast driver (`reverb` or `log`) |

---

## Available Artisan Commands

| Command | Description |
|---|---|
| `php artisan migrate` | Run database migrations |
| `php artisan db:seed` | Seed demo accounts, tickets, departments, and SLA plans |
| `php artisan deskflow:create-admin` | Interactively provision a new administrator account |
| `php artisan queue:work` | Start background queue worker |
| `php artisan schedule:work` | Start local development task scheduler |
| `php artisan sla:check-breaches` | Evaluate all active tickets for SLA deadline breaches |
| `php artisan webpush:vapid` | Generate public and private VAPID keys for browser push |