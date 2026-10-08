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
