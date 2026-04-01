## UPGRADE PACK V1 — QR Restaurant SaaS

This upgrade pack introduces safe, backwards‑compatible improvements focused on infrastructure, security and performance. Core architecture, routing, authentication, and business logic remain unchanged.

### 1. Redis caching (optional)

- New helper `app/cache.php`:
  - `cache_get($key)`, `cache_set($key, $value, $ttl)`, `cache_delete($key)`.
  - Uses Redis only when `REDIS_HOST` is configured **and** the PHP Redis extension is available.
  - Otherwise, calls are no‑ops (no caching), so behaviour stays identical.
- Intended for:
  - analytics / dashboard metrics cache,
  - growth engine cache,
  - other expensive, read‑heavy queries.
- Not used for:
  - authentication / sessions,
  - billing,
  - order creation / core write flows.

### 2. Docker healthcheck and restart policies

- `docker-compose.prod.yml`:
  - `app`, `db`, and `nginx` now have `restart: unless-stopped` for automatic recovery after crashes or reboot.
  - `app` has a `healthcheck` that calls `http://localhost/health.php` every 30 seconds with a short timeout.
- This allows Docker / orchestration to detect unhealthy states and restart containers while keeping the runtime behaviour of the app unchanged.

### 3. Rate limiting (login/signup tuning)

- Existing `app/security.php` already implements Redis‑aware rate limiting with DB/session fallbacks.
- Adjustments:
  - `login.php`: login attempts are now limited to **5 attempts per minute per IP** (was 10).
  - `signup.php`: new limit of **3 signup attempts per minute per IP** based on IP hash.
- New alias helper `app/rate_limit.php` exposes `rate_limit($key, $max_attempts, $window_seconds)` as a thin wrapper around `security_rate_limit()`.

### 4. Admin IP allow‑list (optional)

- New helper in `app/security.php`:
  - `admin_ip_guard()` reads `ADMIN_ALLOWED_IPS` (comma‑separated IPs) from env.
  - If the list is empty, no additional restriction is applied.
  - If set, only requests from allowed IPs can access guarded project‑admin pages; others receive HTTP 403.
- Integrated into:
  - `public_html/project-admin/index.php`
  - `public_html/project-admin/diagnostics.php`
- This is optional hardening for production project‑owner tools; it does not affect restaurant/staff/guest flows.

### 5. Deferred and documented features

The following items from the original upgrade idea are **not** implemented in V1 to avoid risky changes and large surface area:

- Menu image upload pipeline (DB column + file uploads).
- QR table generator UI (bulk ZIP/PDF).
- Kitchen display board and push‑style polling loops.
- Guest feedback table and new public endpoints.
- Google review prompt and associated settings.
- Deep CRM email automation layer and new triggers.
- Additional dashboard widgets and staff performance metrics.

These can be implemented later as separate, scoped changes once the current production foundation is running stably.

