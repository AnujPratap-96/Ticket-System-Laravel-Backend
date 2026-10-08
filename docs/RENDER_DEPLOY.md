# Deploying the DeskFlow API on Render (Docker)

This guide takes the API from a Git repository to a running service on [Render](https://render.com), using the `Dockerfile` in `deskflow-api/`.
The frontend is deployed separately on Vercel (see `DEPLOYMENT_GUIDE.md`).

> **Status:** the image, config files and `render.yaml` were written and syntax-checked (nginx, php-fpm and the entrypoint script all validate), but the Docker image itself has **not been built or run** by the author. Do the *local Docker test* in section 10 first. Expect to fix a small thing or two on the first build.

---

## 1. What runs where

```
 Browser ──► Vercel (React app) ──HTTPS──► Render Web Service  ──► Supabase Postgres  (database)
                                              │  (this Docker image,   │   (cache + queue tables live in the same database;
                                              │                        │    Redis is optional)
                                              │   role = web)          ├─► Cloudinary       (attachments)
                                              │                        ├─► SMTP provider    (OTP + notification email)
        Render Background Worker (role = worker) ─ reads the Redis queue, sends email
        Render Cron Job          (role = cron)   ─ every minute: php artisan schedule:run  (flags SLA breaches)
```

One image, three Render services. The environment variable **`CONTAINER_ROLE`** decides what a container does:

| `CONTAINER_ROLE` | What it runs | Render service type |
|---|---|---|
| `web` (default) | nginx + php-fpm, listening on `$PORT` | Web Service |
| `worker` | `php artisan queue:work` | Background Worker |
| `cron` | `php artisan schedule:run` once, then exits | Cron Job (`* * * * *`) |
| `scheduler` | `php artisan schedule:work` (runs forever) | Background Worker (alternative to cron) |
| `migrate` | `php artisan migrate --force` once, then exits | one-off job |

**Plans:** Render's free plan cannot run background workers or cron jobs. For the standard setup use **Starter** or higher for all three services. **If you want to avoid paying for them, there are free alternatives; see section 12.** Without *some* way of processing the queue, no email is ever sent (signup codes, replies) and SLA breaches are never flagged.

---

## 2. Prerequisites

- A Render account and the code in a Git repository (GitHub/GitLab/Bitbucket).
- **Repository layout** (this guide assumes it):
  ```
  repo-root/
  ├── render.yaml
  ├── deskflow-api/        <- Dockerfile lives here
  └── deskflow-frontend/
  ```
- External services, each with credentials ready:
  | Service | For | Notes |
  |---|---|---|
  | **Postgres** (Supabase) | database | use the **session pooler, port 5432** URL (not 6543) |
  | Redis (optional) | faster cache and queue | not needed: the default setup keeps cache and queue in the database |
  | **SMTP** provider | email | Brevo, SES, Mailgun, Postmark ... |
  | **Cloudinary** | attachments | cloud name, API key, API secret |
  | **Groq** (optional) | AI assistant | API key |

---

## 3. How the port is exposed (nothing to configure)

Render requires a web service to **listen on `0.0.0.0:$PORT`**. `PORT` defaults to **10000**, and Render's port scanner probes exactly that. If nothing is listening, the deploy fails with "no open ports detected".

This image handles it for you:

1. The `Dockerfile` sets `ENV PORT=10000` and `EXPOSE 10000`. Render injects its own `PORT` at runtime, which overrides the default.
2. At start-up `docker/entrypoint.sh` renders `docker/nginx.conf.template` with `envsubst`, replacing `${PORT}`, so nginx runs `listen 0.0.0.0:${PORT};`.
3. nginx forwards PHP requests to php-fpm on `127.0.0.1:9000` inside the same container (never exposed).
4. Render's health check calls `GET /up` (Laravel's built-in health route).

You only need to do something if you **want a different port**: add an environment variable `PORT=8080` to the web service. The container will follow it. (`EXPOSE` is documentation only and does not need to match.)

To confirm in the logs after a deploy, look for:
```
Starting web on 0.0.0.0:10000
```

---

## 4. Environment variables

Create an **Environment Group** called **`deskflow-shared`** first (Render dashboard > *Env Groups* > *New*). All three services link to it, so you enter each secret once.

### Required

| Variable | Example | Where to get it / notes |
|---|---|---|
| `APP_KEY` | `base64:...` | `php artisan key:generate --show` on any machine with PHP. **Never reuse your local key.** |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | `true` exposes stack traces to the internet |
| `APP_NAME` | `DeskFlow` | |
| `APP_URL` | `https://deskflow-api.onrender.com` | the service's public URL (update after the first deploy if needed) |
| `FRONTEND_URL` | `https://your-app.vercel.app` | used in email links and as the default CORS origin |
| `CORS_ALLOWED_ORIGINS` | `https://your-app.vercel.app` | exact origin(s), comma-separated, no trailing slash |
| `LOG_CHANNEL` | `stderr` | so logs appear in Render's Logs tab |
| `LOG_LEVEL` | `warning` | |
| `DB_CONNECTION` | `pgsql` | |
| `DB_URL` | `postgresql://postgres.PROJECT:PASSWORD@aws-0-REGION.pooler.supabase.com:5432/postgres` | Supabase *session pooler*. URL-encode special characters in the password |
| `CACHE_STORE` | `database` | uses the `cache` table in your Postgres (use `redis` only if you have Redis) |
| `QUEUE_CONNECTION` | `database` | uses the `jobs` table (use `redis` only if you have Redis) |
| `SESSION_DRIVER` | `array` | the API is token-based |
| `CRON_SECRET` | 64 random characters | enables the scheduler-by-URL endpoint (section 12). Already generated in `.env.production` |
| `MAIL_MAILER` | `smtp` | with `log` no email is ever sent |
| `MAIL_HOST` | `smtp-relay.brevo.com` | |
| `MAIL_PORT` | `587` | |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | | SMTP credentials |
| `MAIL_FROM_ADDRESS` | `support@yourdomain.com` | a sender your provider has verified (set up SPF and DKIM) |

### Feature variables

| Variable | Purpose |
|---|---|
| `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` | attachments (without them uploads are disabled) |
| `GROQ_API_KEY`, `AI_MODEL=qwen/qwen3.8-27b`, `AI_DAILY_LIMIT=800` | AI assistant (without a key the chat bubble falls back to help articles) |
| `INBOUND_EMAIL_SECRET`, `INBOUND_EMAIL_DEFAULT_DEPARTMENT` | customers replying by email (empty secret = endpoint off) |
| `SANCTUM_EXPIRATION=480` | login lifetime in minutes |

### Set per service, not in the group

| Service | Variable |
|---|---|
| web | `CONTAINER_ROLE=web` |
| worker | `CONTAINER_ROLE=worker` |
| cron | `CONTAINER_ROLE=cron` |

(`render.yaml` already sets these.)

Do **not** set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` or `DB_PASSWORD` when you use `DB_URL`.

---

## 5. Deploy option A: Blueprint (recommended)

1. Push the repo, including `render.yaml` at the root.
2. Create the **`deskflow-shared`** environment group with the variables from section 4.
3. Render dashboard > **New +** > **Blueprint** > connect the repo > select the branch.
4. Render reads `render.yaml` and lists three services: `deskflow-api` (web), `deskflow-worker` and `deskflow-scheduler` (cron). Check the plan is **Starter** (or higher) on each.
5. Click **Apply**. The first build takes several minutes (it downloads PHP extensions and Composer packages).
6. When the web service is **Live**, open `https://<service>.onrender.com/up`. It should return HTTP 200.

What the Blueprint sets for you:
- `runtime: docker`, `dockerfilePath: ./deskflow-api/Dockerfile`, `dockerContext: ./deskflow-api`
- `healthCheckPath: /up`
- `preDeployCommand: php artisan migrate --force --isolated`, which applies database migrations once per deploy, before traffic switches to the new version
- `fromGroup: deskflow-shared` plus the right `CONTAINER_ROLE` for each service

---

## 6. Deploy option B: manual (dashboard)

### 6.1 Web service
1. **New +** > **Web Service** > connect the repo.
2. **Language:** `Docker`.
3. **Root Directory:** `deskflow-api` (so Render builds from that folder).
4. **Dockerfile Path:** `./Dockerfile`.
5. **Instance type:** Starter or higher.
6. **Health Check Path:** `/up`.
7. **Pre-Deploy Command:** `php artisan migrate --force --isolated`.
8. **Environment:** link the `deskflow-shared` group and add `CONTAINER_ROLE=web`.
9. **Create Web Service**.

### 6.2 Background worker (emails and SLA warning jobs)
1. **New +** > **Background Worker**, same repo, Language `Docker`, Root Directory `deskflow-api`.
2. Link `deskflow-shared`, add `CONTAINER_ROLE=worker`. Leave the Docker Command empty.

### 6.3 Cron job (SLA breach check)
1. **New +** > **Cron Job**, same repo, Language `Docker`, Root Directory `deskflow-api`.
2. **Schedule:** `* * * * *` (every minute).
3. Link `deskflow-shared`, add `CONTAINER_ROLE=cron`. Leave the Docker Command empty.

---

## 7. First-time setup after the first deploy

### 7.1 Check the logs
Web service > **Logs**. You should see:
```
Starting web on 0.0.0.0:10000
```
and then request lines. The Pre-Deploy log shows the migrations that ran.

### 7.2 Create the first administrator
Do **not** run the demo seeder in production (it creates accounts with the password `password123`).

Web service > **Shell** tab (available on paid instances):

```bash
su-exec www-data php artisan deskflow:create-admin --name="Your Name" --email=you@company.com
```
It asks for a password (minimum 12 characters). **Always prefix artisan commands with `su-exec www-data`** in the Shell: the Shell runs as root, and running artisan as root creates cache files that the web process cannot write later.

Then sign in at your Vercel URL, and:
1. Turn on **two-factor authentication** (profile menu > My account).
2. **Manage > Departments:** create each department with hours and timezone.
3. **Manage > Holidays** and **SLA policies:** configure.
4. **Manage > Team:** add agents and leads. This is the only way staff accounts are created.
5. Customers get an organization by **email domain**, so insert organizations before customers register (there is no screen for this yet):
   ```bash
   su-exec www-data php artisan tinker
   >>> App\Models\Organization::create(['name'=>'Acme','domain'=>'acme.com','sla_tier'=>'gold','is_active'=>true]);
   ```

### 7.3 Send a test email
```bash
su-exec www-data php artisan tinker
>>> Mail::raw('DeskFlow test', fn($m) => $m->to('you@yourdomain.com')->subject('Test'));
```
Emails are queued, so the **worker** must be running. Then register a new customer on the live site and confirm the code email arrives.

---

## 8. Connect the frontend (Vercel)

1. Vercel project: Root Directory `deskflow-frontend`, environment variable `VITE_API_URL=https://<service>.onrender.com/api/v1`. Redeploy (it is baked in at build time).
2. Copy the Vercel URL into `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` in the `deskflow-shared` group.
3. **Restart** the web service, worker and cron (environment changes apply on restart).

If you add a custom domain on Vercel, add it to `CORS_ALLOWED_ORIGINS` too (comma-separated).

---

## 9. Day-two operations

| Task | How |
|---|---|
| Deploy a change | Push to the connected branch. Render rebuilds and swaps the web service with zero downtime. |
| Change a variable | Edit the env group, then **Manual Deploy > Restart** each service. |
| Run a migration | Automatic (Pre-Deploy Command). To run by hand: Shell > `su-exec www-data php artisan migrate --force` |
| See failed emails | Shell > `su-exec www-data php artisan queue:failed` |
| Roll back | Service > **Events/Deploys** > pick a previous deploy > **Rollback**. Database migrations are not rolled back automatically. |
| Scale | Increase instances on the web service (stateless; sessions are tokens, cache/queue are in Redis). Keep **one** worker unless the queue backs up. |
| Custom domain | Service > **Settings > Custom Domains**; Render issues the TLS certificate. Update `APP_URL`. |
| Logs | Service > **Logs** (all roles write to stderr/stdout). |

Inbound email (optional): configure your mail provider's webhook to `POST https://<service>.onrender.com/api/v1/webhooks/inbound-email` with the header `X-Webhook-Secret: <INBOUND_EMAIL_SECRET>`.

---

## 10. Test the image locally before pushing (strongly recommended)

You need Docker. From `deskflow-api/`:

```bash
cp .env.docker.example .env.docker
php artisan key:generate --show          # paste into APP_KEY in .env.docker
docker compose --env-file .env.docker up --build
```
This starts the API (http://localhost:8000), a worker, the scheduler, Postgres, Redis (local only) and the frontend (http://localhost:5173). Check:

```bash
curl -i http://localhost:8000/up                                     # HTTP 200
docker compose logs api | grep "Starting web"                        # Starting web on 0.0.0.0:10000
docker compose exec api su-exec www-data php artisan deskflow:create-admin
```

To test a **single container the way Render runs it**:
```bash
docker build -t deskflow-api ./deskflow-api
docker run --rm --env-file deskflow-api/.env.docker -e PORT=3456 -p 3456:3456 deskflow-api
curl -i http://localhost:3456/up                                     # proves the container follows $PORT
```

---

## 11. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Deploy fails: *"No open ports detected"* / health check timeout | Nothing listening on `$PORT`. Check Logs for `Starting web on ...`. A crash before that (missing `APP_KEY`, bad config) stops the container. Fix the error shown just above. |
| Logs: `FATAL: APP_KEY is not set` | Add `APP_KEY` to the env group. |
| `502 Bad Gateway` right after deploy | php-fpm not ready or crashed. Check Logs for PHP errors; confirm the env group is linked to the web service. |
| `500` on every request, log mentions database | Wrong `DB_URL`, or you used port 6543. Use the Supabase *session pooler* on 5432 with `DB_SSLMODE=require`. |
| `SQLSTATE ... could not find driver` | Image built without `pdo_pgsql`. Rebuild; check the `install-php-extensions` step in the build log. |
| Browser shows a CORS error | `CORS_ALLOWED_ORIGINS` doesn't exactly match the Vercel origin. Fix it and restart all services. |
| Signup code / emails never arrive | Worker not running or on the free plan, `MAIL_MAILER` not `smtp`, wrong SMTP credentials, or unverified sender. Check the worker's Logs and `queue:failed`. |
| SLA breaches never flagged | The cron service is missing or failing. Check its Logs for a run each minute. |
| `Permission denied` writing to `storage/` or `bootstrap/cache` | You ran an artisan command as root in the Shell. Always use `su-exec www-data php artisan ...`. Fix existing files: `chown -R www-data:www-data storage bootstrap/cache`. |
| Variables seem ignored | You changed the group but did not restart the services. Config is cached at start-up. |
| Assignments hang or deadlock | Postgres pooler in *transaction* mode (port 6543). Use 5432. |
| Build fails pulling `ghcr.io/mlocati/php-extension-installer` | Temporary registry problem; retry the deploy. |
| Migration fails on first deploy | Read the Pre-Deploy log; typically a wrong `DB_URL`. After fixing, redeploy. |


---

## 12. Running without a paid worker or cron job

The queue worker and the scheduler only need to *run somewhere*. Pick one of these setups. They all use the same image.

| | A. Standard | B. Web + pinger | C. All-in-one container | D. No queue |
|---|---|---|---|---|
| Services on Render | web + worker + cron | **web only** | **web only** | **web only** |
| Plan | Starter+ for each | Free is possible | Free is possible | Free is possible |
| Emails | queued, sent within seconds | sent within about a minute | queued, within seconds | sent **during the request** (slower) |
| SLA breach check | every minute, reliable | every minute, if the pinger works | every minute while awake | needs a pinger (B) |
| 15-minute SLA warnings | yes | yes (within a minute of due) | yes | **no** (delayed jobs need a queue) |
| Weak point | cost | depends on an external service | stops when the instance sleeps or restarts | request latency, no warnings |
| Best for | production | hobby, demos, small teams | demos | quick tests only |

### B. Web service + an external pinger ("cron by URL")

An external scheduler calls a protected endpoint every minute. Each call (1) runs the Laravel scheduler and (2) processes waiting queue jobs for up to about 25 seconds, so it replaces both the worker and the cron service.

1. Keep `QUEUE_CONNECTION=database` and set a long random **`CRON_SECRET`** in the env group (`openssl rand -hex 32`). While `CRON_SECRET` is empty the endpoint is switched off (it returns 503).
2. Create the job in an external service. **cron-job.org** (free) works well because it can send POST requests with custom headers:
   - URL: `https://<service>.onrender.com/api/v1/internal/tick`
   - Method: `POST`, schedule: every minute
   - Header: `X-Cron-Secret: <your CRON_SECRET>`
   - Request timeout: 60 seconds or more (a sleeping free instance needs 30 to 60 seconds to wake)
3. Test it: `curl -i -X POST https://<service>.onrender.com/api/v1/internal/tick -H "X-Cron-Secret: <secret>"` should return `{"status":"ok", ...}`.

Notes:
- The secret is accepted **only in the header**, never in the URL, because URLs end up in logs.
- Overlapping calls are refused with `202 {"status":"busy"}`; the endpoint is also rate limited.
- GitHub Actions `schedule:` can do the same job but runs at best every 5 minutes and is not guaranteed on time, so cron-job.org is better.
- A pinging service that can only do plain GET requests (for example UptimeRobot's free plan) cannot call this endpoint, but it is still useful for keeping the instance awake by requesting `/up`.
- Free web services go to sleep after a period of inactivity (15 minutes at the time of writing). A ping every minute keeps the instance awake. Check Render's current free-plan limits on monthly instance hours before relying on this.

### C. Worker and scheduler inside the web container

Set these on the web service:

```
RUN_WORKER_IN_WEB=true
RUN_SCHEDULER_IN_WEB=true
RUN_MIGRATIONS=true        # Pre-Deploy Commands are not available on free instances
```
The container then also runs `queue:work` and `schedule:work` next to nginx and php-fpm (supervisor starts them). `render.free.yaml` at the repository root is a ready-made Blueprint for this.

Trade-offs: the extra processes share the instance's CPU and memory; they stop when the instance sleeps or restarts, so keep it awake with an external ping of `/up` every 5 to 10 minutes; and if you later run **more than one** web instance, every instance runs its own scheduler (the breach check is idempotent, so this is harmless but wasteful) and you should move to setup A.

### D. No queue at all

`QUEUE_CONNECTION=sync` sends emails during the request, so replying and signing up feel slower, and an SMTP outage slows requests down. The **15-minute SLA warning jobs never fire**, because delayed jobs need a real queue. Use this for quick tests, not for real use.

### Which would I choose?

- Real customers: **A**, or **B** if the budget is zero and a minute of email delay is acceptable.
- Demo or portfolio: **C**, plus a keep-awake ping.
