# Backlog Triage

## 1) CRITICAL

- Eliminate any remaining core-flow fatals (`qr`, `kitchen_api`, `orders_api`, `dashboard`).
- Remove remaining malformed JSON risks in staff/guest AJAX endpoints.
- Keep runtime schema bootstrap idempotent under repeated concurrent requests.

## 2) STABILITY

- Reduce operational notice noise (for example loyalty-disabled sync notices).
- Tighten smoke scripts to match current auth/context behavior.
- Extend system health checks with clear degraded-mode explanations.

## 3) PRODUCT

- Improve operational UX in existing screens (kitchen/floorplan/orders/courier) without backend rewrites.
- Continue controlled compatibility for mixed legacy/live schemas.
- Harden tenant context UX for direct-access operational pages.

## 4) GROWTH

- Improve promo + upsell operational visibility and guardrails.
- Expand CRM/RFM insights presentation with strict performance limits.
- Add safe retention playbooks documentation before automation.

## 5) AI / AUTOMATION

- Keep forecasting layer AI-ready via stable interfaces only.
- Define explicit hooks/contracts for future model-driven features.
- Defer external AI integrations until stability SLOs are met.

## 6) NICE TO HAVE

- Unified operational runbooks for owner/staff roles.
- More detailed release notes templates and changelog automation.
- Optional frontend diagnostics overlays for non-production environments.

## Current policy

- No large feature rollout before stability and critical backlog are green.
- Any change touching hotspot files requires targeted smoke-test signoff.
