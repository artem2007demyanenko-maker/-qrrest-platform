# Observability Baseline (Lightweight)

## Current production-safe baseline

- Request correlation ID (RID): generated per request in `app/bootstrap.php`.
- Structured runtime error logging: `STABILITY_ERROR` in `app/error_handler.php`.
- Runtime schema warning tagging: `app/runtime_schema_bootstrap.php`.
- Liveness/readiness endpoints:
  - `public_html/health.php`
  - `public_html/ready.php`

## Validation commands

- `curl -sS https://<domain>/health.php`
- `curl -sS https://<domain>/ready.php`
- `docker logs --tail 150 <web-container>`

## Log classification

- `critical`: fatal, uncaught exception, SQL fatal.
- `warning`: schema fallback/degraded module warnings.
- `notice`: operational informational events.

## Scope guard

- No heavy monitoring stack in this phase.
- No Prometheus/Grafana requirement in current baseline.
