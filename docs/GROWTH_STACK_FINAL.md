# Growth Stack — Consolidation & Finalization

This document describes the consolidated growth platform inside QR Restaurant SaaS.

## Modules included

- **Smart Upsell**
  - Core engine: `app/upsell_engine.php`
  - Manual rule suggestions: `app/upsell.php` (`menu_upsell_rules`)
  - Restaurant-managed rules UI/storage: `public_html/restaurant/upsells.php` (`menu_item_upsells`)
  - Tracking: `app/upsell_analytics.php` (`upsell_events`)
- **Auto Upsell Optimization (suggestions only)**
  - `app/upsell_optimization.php`
  - Manual approval UI: `public_html/restaurant/upsells.php#optimization`
- **CRM Retention / Guest Return**
  - Retention analytics helpers: `app/guest_retention.php` (prefers `crm_guests` when present)
  - Return candidates: `app/guest_return_engine.php`
  - CRM suggestions/drafts: `app/growth_engine.php` + restaurant CRM pages
- **Dynamic Menu Intelligence (read-only)**
  - `app/menu_intelligence.php`
  - Dashboard widget: `public_html/restaurant/dashboard.php`
  - Revenue page section: `public_html/restaurant/revenue.php`
- **Restaurant Health Score (transparent, weighted)**
  - `app/restaurant_health.php`
  - Dashboard card: `public_html/restaurant/dashboard.php`
- **Unified Growth Engine Architecture Layer (integration)**
  - `app/growth_engine_arch.php`
  - Dashboard section: `public_html/restaurant/dashboard.php`

## Source-of-truth decisions

- **Upsell rules**
  - **Operational rules UI/storage**: `menu_item_upsells` (managed in `restaurant/upsells.php`)
  - **Legacy/manual engine rules**: `menu_upsell_rules` (still supported by `app/upsell.php`)
  - **Smart upsell rendering**: `app/upsell_engine.php` (prefers manual rules, then order pairings, then category fallback)
- **Retention analytics**
  - Prefer **CRM tables** (`crm_guests`) when present.
  - Fallback to `guest_visits` only for legacy compatibility.
- **Health score**
  - The dashboard’s canonical “Health Score” is from `app/restaurant_health.php` (transparent breakdown + recommendations).
  - The older Success Engine (`app/restaurant_success.php`) remains available for insights/recommendations, but its duplicate health display is removed from the dashboard UI.

## Unified opportunity schema

All unified growth opportunities use:

```json
[
  {
    "type": "upsell_improvement | guest_return | loyalty_activation | menu_fix | health_warning",
    "priority": "high | medium | low",
    "title": "string",
    "description": "string",
    "source_module": "string",
    "estimated_impact": "string (conservative)",
    "action_url": "string"
  }
]
```

Implemented by `growth_engine_arch_get_opportunities($restaurantId)`.

## Dashboard blocks (intended order)

1. **Restaurant Health Score** (transparent, weighted)
2. **Guest Return Opportunities** (CRM/comeback candidates)
3. **Upsell Optimization** + **Upsell performance**
4. **Menu Intelligence**
5. **Growth Engine** (unified top opportunities + summary)

Other blocks (copilot, legacy growth opportunities, etc.) remain available but should avoid duplicating the above.

## Caching (safe fallback)

Caching uses `app/cache.php` when Redis is configured; otherwise it’s a no-op.

TTL targets:
- **Dashboard metrics**: 5 minutes (implemented in callers/modules where applicable)
- **Menu Intelligence**: 10 minutes (`menu_intelligence`)
- **Upsell optimization stats/suggestions**: 10 minutes (`upsell_optimization`)
- **Health score breakdown**: 5 minutes (`restaurant_health`)
- **Growth engine opportunities + summary**: 5 minutes (`growth_engine_arch`)

## Demo mode behavior

- Demo mode returns realistic **fake** insights/opportunities/scores.
- **No writes** occur in demo mode:
  - no `growth_engine_suggestions` insertion
  - no upsell rule mutations
  - no CRM message sends/drafts
  - no background “publishing suggestions” on page load

## Safety guarantees

- **Tenant isolation**: all analytics and suggestions are filtered by `restaurant_id`.
- **No auto-mutation**: optimization layers generate **suggestions only**; applying changes requires explicit owner/admin action.
- **No auto-send**: CRM drafts/suggestions never send automatically.
- **No auto-publish on view**: dashboards/pages do not insert suggestion rows just by being opened.
- **Graceful degradation**: if optional tables are missing, modules return safe defaults and UI shows “not enough data”.

## Remaining limitations

- Estimated impact is intentionally conservative and human-readable (not hard $ attribution) to avoid misleading causality.
- Some features depend on optional tables (`upsell_events`, `order_feedback`, `growth_engine_suggestions`). When missing, the platform falls back to minimal behavior.

