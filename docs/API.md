# DeskFlow API reference

Generated from the application's routes (`php artisan route:list --path=api`). Base URL: `https://<your-api>/api/v1`.

- **Auth:** `Authorization: Bearer <token>` (Laravel Sanctum). Get a token from `POST /auth/login`, `POST /auth/verify-otp` (after signup) or `POST /auth/accept-invite` (staff invitation).
- **Roles:** `customer`, `agent`, `lead`, `admin`. Ticket access is further limited by the ticket policy: admins see everything; leads their department; agents the tickets assigned to them, their department's unassigned tickets and tickets they watch (or were @mentioned on); customers their own tickets, and a *company admin* customer also their organization's.
- **Errors:** JSON `{ "message": "…", "code": "…" }`. Validation errors are `422` with an `errors` object. Notable codes: `agent_collision` (409), `agent_at_capacity` (409), `invalid_state_transition` (422), `email_unverified`, `account_inactive` and `invite_pending` (403).
- **Throttling:** `requests,minutes` or a named limiter: `login`, `otp`, `assistant`, `ai-staff`.
- **Realtime:** private WebSocket channels `user.{id}`, `ticket.{id}` and `ticket.{id}.staff` (Laravel Reverb). Channel auth is `POST /api/v1/broadcasting/auth` with the same bearer token. Pings contain no ticket content.
- **Outbound webhooks:** `X-DeskFlow-Signature: sha256=HMAC(secret, timestamp + "." + body)` with `X-DeskFlow-Timestamp`; retried 4 times (30 s, 2 min, 10 min).
- **Access column:** `public` needs no token; `signed in` needs one; `roles:` restricts it further.

| Group | Routes |
|---|---|
| Authentication | 15 |
| Two-factor authentication | 3 |
| Profile (name and photo) | 4 |
| Privacy (GDPR) | 2 |
| Tickets | 25 |
| Saved views | 3 |
| Notifications and push | 6 |
| Attachments | 1 |
| Canned responses | 4 |
| Help center (public and staff) | 9 |
| AI assistant and settings | 3 |
| Analytics and reports | 8 |
| Customer plan (SLA) | 1 |
| Users, team and invitations | 6 |
| Organizations | 6 |
| Departments and holidays | 7 |
| Automation rules | 6 |
| Integrations (Slack, Teams, webhooks) | 5 |
| Realtime | 1 |
| Inbound email and scheduler | 2 |

## Authentication

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `POST` | `/api/v1/auth/accept-invite` | public | — | otp |
| `POST` | `/api/v1/auth/forgot-password` | public | — | otp |
| `GET` | `/api/v1/auth/invite-info` | public | — | otp |
| `POST` | `/api/v1/auth/login` | public | — | login |
| `POST` | `/api/v1/auth/logout` | signed in | — | — |
| `GET` | `/api/v1/auth/me` | signed in | — | — |
| `PATCH` | `/api/v1/auth/password` | signed in | — | 10,1 |
| `POST` | `/api/v1/auth/register` | public | — | otp |
| `POST` | `/api/v1/auth/resend-otp` | public | — | otp |
| `POST` | `/api/v1/auth/reset-password` | public | — | otp |
| `GET` | `/api/v1/auth/sessions` | signed in | — | — |
| `POST` | `/api/v1/auth/sessions/revoke-others` | signed in | — | — |
| `DELETE` | `/api/v1/auth/sessions/{id}` | signed in | — | — |
| `POST` | `/api/v1/auth/two-factor-challenge` | public | — | login |
| `POST` | `/api/v1/auth/verify-otp` | public | — | otp |

## Two-factor authentication

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `POST` | `/api/v1/2fa/confirm` | signed in | — | — |
| `POST` | `/api/v1/2fa/disable` | signed in | — | — |
| `POST` | `/api/v1/2fa/setup` | signed in | — | — |

## Profile (name and photo)

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `DELETE` | `/api/v1/auth/avatar` | signed in | — | 20,1 |
| `POST` | `/api/v1/auth/avatar` | signed in | — | 20,1 |
| `POST` | `/api/v1/auth/avatar/sign` | signed in | — | 20,1 |
| `PATCH` | `/api/v1/auth/profile` | signed in | — | 20,1 |

## Privacy (GDPR)

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `DELETE` | `/api/v1/me` | signed in | — | — |
| `GET` | `/api/v1/me/export` | signed in | — | — |

## Tickets

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/tags` | signed in | roles: agent, lead, admin | — |
| `GET` | `/api/v1/tickets` | signed in | — | — |
| `POST` | `/api/v1/tickets` | signed in | — | — |
| `GET` | `/api/v1/tickets-similar` | signed in | — | 30,1 |
| `POST` | `/api/v1/tickets/bulk` | signed in | roles: agent, lead, admin | — |
| `GET` | `/api/v1/tickets/{ticket}` | signed in | — | — |
| `POST` | `/api/v1/tickets/{ticket}/ai/analyze` | signed in | roles: agent, lead, admin | ai-staff |
| `POST` | `/api/v1/tickets/{ticket}/ai/draft-reply` | signed in | roles: agent, lead, admin | ai-staff |
| `POST` | `/api/v1/tickets/{ticket}/ai/summary` | signed in | roles: agent, lead, admin | ai-staff |
| `POST` | `/api/v1/tickets/{ticket}/ai/translate` | signed in | roles: agent, lead, admin | ai-staff |
| `POST` | `/api/v1/tickets/{ticket}/ai/triage/accept` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/tickets/{ticket}/ai/triage/dismiss` | signed in | roles: agent, lead, admin | — |
| `PATCH` | `/api/v1/tickets/{ticket}/assign` | signed in | roles: agent, lead, admin | — |
| `GET` | `/api/v1/tickets/{ticket}/audits` | signed in | — | — |
| `GET` | `/api/v1/tickets/{ticket}/mentionable` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/tickets/{ticket}/merge` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/tickets/{ticket}/messages` | signed in | — | — |
| `POST` | `/api/v1/tickets/{ticket}/presence` | signed in | — | — |
| `PATCH` | `/api/v1/tickets/{ticket}/priority` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/tickets/{ticket}/rating` | signed in | — | — |
| `GET` | `/api/v1/tickets/{ticket}/similar` | signed in | roles: agent, lead, admin | — |
| `PATCH` | `/api/v1/tickets/{ticket}/status` | signed in | — | — |
| `PUT` | `/api/v1/tickets/{ticket}/tags` | signed in | roles: agent, lead, admin | — |
| `DELETE` | `/api/v1/tickets/{ticket}/watch` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/tickets/{ticket}/watch` | signed in | roles: agent, lead, admin | — |

## Saved views

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/saved-views` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/saved-views` | signed in | roles: agent, lead, admin | — |
| `DELETE` | `/api/v1/saved-views/{view}` | signed in | roles: agent, lead, admin | — |

## Notifications and push

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/notifications` | signed in | — | — |
| `POST` | `/api/v1/notifications/read-all` | signed in | — | — |
| `POST` | `/api/v1/notifications/{id}/read` | signed in | — | — |
| `GET` | `/api/v1/push/key` | signed in | — | — |
| `POST` | `/api/v1/push/subscribe` | signed in | — | 20,1 |
| `POST` | `/api/v1/push/unsubscribe` | signed in | — | 20,1 |

## Attachments

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `POST` | `/api/v1/attachments/sign` | signed in | — | 60,1 |

## Canned responses

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/canned-responses` | signed in | roles: agent, lead, admin | — |
| `POST` | `/api/v1/canned-responses` | signed in | roles: agent, lead, admin | — |
| `DELETE` | `/api/v1/canned-responses/{cannedResponse}` | signed in | roles: agent, lead, admin | — |
| `PATCH` | `/api/v1/canned-responses/{cannedResponse}` | signed in | roles: agent, lead, admin | — |

## Help center (public and staff)

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/kb-manage/analytics` | signed in | roles: lead, admin | — |
| `GET` | `/api/v1/kb-manage/articles` | signed in | roles: lead, admin | — |
| `POST` | `/api/v1/kb-manage/articles` | signed in | roles: lead, admin | — |
| `DELETE` | `/api/v1/kb-manage/articles/{article}` | signed in | roles: lead, admin | — |
| `PATCH` | `/api/v1/kb-manage/articles/{article}` | signed in | roles: lead, admin | — |
| `GET` | `/api/v1/kb/articles` | public | — | 60,1 |
| `GET` | `/api/v1/kb/articles/{slug}` | public | — | 60,1 |
| `POST` | `/api/v1/kb/articles/{slug}/vote` | public | — | 20,1 |
| `GET` | `/api/v1/kb/categories` | public | — | 60,1 |

## AI assistant and settings

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/ai/settings` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/ai/settings` | signed in | roles: admin | — |
| `POST` | `/api/v1/assistant/chat` | public | — | assistant |

## Analytics and reports

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/analytics/agent-workload` | signed in | roles: lead, admin | — |
| `GET` | `/api/v1/analytics/satisfaction` | signed in | roles: lead, admin | — |
| `GET` | `/api/v1/analytics/sla-overview` | signed in | roles: lead, admin | — |
| `GET` | `/api/v1/analytics/trends` | signed in | roles: lead, admin | — |
| `GET` | `/api/v1/audits` | signed in | roles: admin | — |
| `GET` | `/api/v1/sla-policies` | signed in | roles: lead, admin | — |
| `POST` | `/api/v1/sla-policies` | signed in | roles: admin | — |
| `GET` | `/api/v1/tickets-export` | signed in | roles: lead, admin | — |

## Customer plan (SLA)

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/me/sla` | signed in | — | — |

## Users, team and invitations

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/users` | signed in | roles: lead, admin | — |
| `POST` | `/api/v1/users` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/users/{user}` | signed in | roles: admin | — |
| `POST` | `/api/v1/users/{user}/erase` | signed in | roles: admin | — |
| `POST` | `/api/v1/users/{user}/resend-invite` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/users/{user}/routing` | signed in | roles: lead, admin | — |

## Organizations

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/organizations` | signed in | roles: admin | — |
| `POST` | `/api/v1/organizations` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/organizations/{organization}` | signed in | roles: admin | — |
| `POST` | `/api/v1/organizations/{organization}/attach-customers` | signed in | roles: admin | — |
| `GET` | `/api/v1/organizations/{organization}/customers` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/organizations/{organization}/customers/{user}` | signed in | roles: admin | — |

## Departments and holidays

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/departments` | signed in | — | — |
| `POST` | `/api/v1/departments` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/departments/{department}` | signed in | roles: admin | — |
| `GET` | `/api/v1/departments/{department}/agents` | signed in | — | — |
| `GET` | `/api/v1/holidays` | signed in | roles: admin | — |
| `POST` | `/api/v1/holidays` | signed in | roles: admin | — |
| `DELETE` | `/api/v1/holidays/{holiday}` | signed in | roles: admin | — |

## Automation rules

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/automation-rules` | signed in | roles: admin | — |
| `POST` | `/api/v1/automation-rules` | signed in | roles: admin | — |
| `DELETE` | `/api/v1/automation-rules/{rule}` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/automation-rules/{rule}` | signed in | roles: admin | — |
| `GET` | `/api/v1/automation-rules/{rule}/runs` | signed in | roles: admin | — |
| `POST` | `/api/v1/automation-rules/{rule}/toggle` | signed in | roles: admin | — |

## Integrations (Slack, Teams, webhooks)

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/webhooks` | signed in | roles: admin | — |
| `POST` | `/api/v1/webhooks` | signed in | roles: admin | — |
| `DELETE` | `/api/v1/webhooks/{webhook}` | signed in | roles: admin | — |
| `PATCH` | `/api/v1/webhooks/{webhook}` | signed in | roles: admin | — |
| `POST` | `/api/v1/webhooks/{webhook}/test` | signed in | roles: admin | 10,1 |

## Realtime

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `GET` | `/api/v1/broadcasting/auth` | signed in | — | — |

## Inbound email and scheduler

| Method | Path | Access | Roles | Throttle |
|---|---|---|---|---|
| `POST` | `/api/v1/internal/tick` | public | — | 30,1 |
| `POST` | `/api/v1/webhooks/inbound-email` | public | — | 120,1 |

## Added after this table was generated

| Method | Path | Access | Roles | Notes |
|---|---|---|---|---|
| `DELETE` | `/api/v1/organizations/{id}?move_to={id}` | signed in | roles: admin | Unlinks its people. If it has tickets, `move_to` (another organization) is required; open tickets then follow that plan's SLA |
| `DELETE` | `/api/v1/users/{id}` | signed in | roles: admin | Deletes the account when it has no history, otherwise suspends it (`outcome`: `deleted` / `suspended`). Reactivate with `PATCH /users/{id}` `{ "is_active": true }` |
| `POST` | `/api/v1/users/{id}/erase` | signed in | roles: admin | Right to erasure: anonymises the person (typed `confirm_email` required) |
| `GET` | `/api/v1/usage` | signed in | roles: admin | Per-organization usage against limits, AI usage today, platform caps |

**Plan limits.** `PATCH/POST /organizations` accept `monthly_ticket_limit`, `max_customers`, `max_open_per_customer` (empty = plan default from `config/plans.php`, `0` = unlimited); organization responses include `overrides`, `limits` and `usage`. `GET /me/sla` returns the customer's own company `limits.plan` and `limits.usage`.
When a limit is reached, ticket creation, sign-up (`POST /auth/register`) and adding customers answer `422` with `{ "code": "quota_exceeded", "limit": "monthly_tickets" | "open_per_customer" | "customers", "message": "…" }`.
`POST /tickets` is limited to 5 a minute and 30 an hour per person, `POST /tickets/{id}/messages` to 20 a minute (`429`).

## Workspace settings, security and operations

| Method | Path | Access | Notes |
|---|---|---|---|
| `GET`/`PATCH` | `/api/v1/settings/branding` | admin | `name`, `brand_color` (`#rrggbb`), `sender_name`, `reply_to_email` |
| `POST` | `/api/v1/settings/branding/logo/sign`, `/settings/branding/logo` | admin | Signed Cloudinary upload, then attach by `public_id`; `DELETE /settings/branding/logo` removes it |
| `GET`/`PATCH`/`DELETE` | `/api/v1/settings/email-channel` | admin | Default department / turn off. `POST /settings/email-channel/enable` creates or rotates the secret and returns it **once** with the webhook URL |
| `GET`/`PATCH` | `/api/v1/settings/security` | admin | `require_two_factor` (the admin must already have 2FA) |
| `GET` | `/api/v1/settings/export` | admin | JSON download of the workspace's data (3 an hour) |
| `GET` | `/api/v1/admin-activity` | admin | Paginated log of admin changes (no request bodies) |
| `PATCH` | `/api/v1/auth/availability` | staff | `{ away_until, release_tickets }`; `null` = back |
| `GET` | `/api/v1/platform/health` | platform owner | Database, scheduler, queue and backup checks |
| `DELETE` | `/api/v1/platform/tenants/{id}` | platform owner | Permanent; body `{ "confirm_slug": "<slug>" }` |

Departments accept `working_days` (ISO weekdays 1-7). `POST /auth/register`, `/auth/forgot-password` and `/workspaces/register` take `captcha_token` when `TURNSTILE_SECRET` is set (`422 captcha_failed` otherwise). After 5 wrong passwords `POST /auth/login` answers `429 account_locked` for 15 minutes. With 2FA required, staff without it get `403 two_factor_setup_required` everywhere except `/auth/*` and `/2fa/*`; `GET /auth/me` returns `two_factor_setup_required`.

## Workspaces (multi-tenancy)

Every route above runs inside **one workspace**. Signed-in requests use the person's own workspace automatically. Before sign-in (login, sign-up, public help centre, inbound email) say which one: `X-Tenant: <slug>` header, `?tenant=<slug>`, or the subdomain when `TENANCY_BASE_DOMAIN` is set. Errors: `400 workspace_required`, `404 workspace_not_found`, `403 workspace_suspended`, `403 platform_account` (the platform owner cannot call workspace routes).

| Method | Path | Access | Notes |
|---|---|---|---|
| `GET` | `/api/v1/workspaces/{slug}` | public | Name of a workspace (for sign-in pages) |
| `GET` | `/api/v1/workspaces/available?slug=` | public | Is this address free? |
| `POST` | `/api/v1/workspaces/register` | public | Start creating a workspace (emails a code) |
| `POST` | `/api/v1/workspaces/verify` | public | Finish with the code; creates the workspace and its administrator |
| `POST` | `/api/v1/platform/login` | public | Platform owner sign-in |
| `GET` | `/api/v1/platform/me`, `POST /platform/logout` | platform owner | |
| `GET` | `/api/v1/platform/tenants` | platform owner | Workspaces with counts only (people, tickets) |
| `POST` | `/api/v1/platform/tenants` | platform owner | Create a workspace and invite its first admin |
| `PATCH` | `/api/v1/platform/tenants/{id}` | platform owner | Rename, suspend (`is_active: false`) or restore |
