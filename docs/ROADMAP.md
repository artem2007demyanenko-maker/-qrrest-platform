# QR Restaurant SaaS — YC-Level Product Roadmap

A 15-step roadmap to evolve the product into a scalable, high-value SaaS for restaurants. Focus: **product growth**, **restaurant value**, **retention**, **monetization**, and **acquisition**.

---

## Stage 1 — Product polish

*Make the product feel premium, reduce friction, and increase activation.*

---

### Step 1 — First-session activation flow

| | |
|---|---|
| **Problem** | New signups land on a blank dashboard and don’t know what to do first; activation and time-to-value drop. |
| **Why it matters for restaurants** | Owners need to see value in the first 10 minutes (e.g. “I added my first dish” or “I saw my first order”) or they churn. |
| **Implementation** | After signup/onboarding, show a **first-session checklist** (add 3 menu items → create 1 table → print QR → place test order). Persist in `user_meta` or `onboarding_progress`. Highlight one next step on the dashboard until 100%. Optional: small celebration when “first order” is received. |
| **Impact** | Higher Day-1 activation, lower early churn, clearer path from signup to “restaurant is live.” |

---

### Step 2 — Live order pulse & kitchen view

| | |
|---|---|
| **Problem** | Owners and kitchen staff don’t have a single place to see “what’s happening right now” without opening multiple screens. |
| **Why it matters for restaurants** | Peak hours require instant visibility: new orders, status changes, and table context to reduce errors and stress. |
| **Implementation** | Add a **Live orders** (or **Kitchen view**) page: list of active orders (new → cooking → ready) with optional auto-refresh (e.g. every 15–30s or WebSocket later). Reuse `orders` + `order_items`; filter by `order_status NOT IN ('delivered','canceled')`. Optional: sound or browser notification for new orders. |
| **Impact** | Feels like a “live system,” reduces missed orders, supports waiter/POS workflow and future kitchen displays. |

---

### Step 3 — Demo-to-trial conversion loop

| | |
|---|---|
| **Problem** | Demo visitors understand the product but leave without a clear “start for real” moment; demo traffic doesn’t convert. |
| **Why it matters for restaurants** | Every demo visitor is a potential customer; reducing friction from “I tried it” to “I started my trial” increases signups. |
| **Implementation** | After 3 demo pages (already tracked), show **“Like what you see? Start your own restaurant in minutes”** with **Start Free Trial** (already in place). Add: (1) optional email capture on demo exit intent or after “Place demo order,” (2) post-demo email with signup link and “your demo link” CTA, (3) track demo → signup in analytics. No backend change to core demo logic. |
| **Impact** | Higher demo → trial conversion, measurable funnel from landing/demo to signup. |

---

### Step 4 — Empty-state and onboarding copy

| | |
|---|---|
| **Problem** | Empty CRM, empty campaigns, “no data” revenue screens feel dead and don’t guide the next action. |
| **Why it matters for restaurants** | Every empty state is a moment to educate and prompt one concrete action (e.g. “Add a dish,” “Send your first comeback message”). |
| **Implementation** | Audit empty states (CRM, campaigns, revenue, upsells, menu insights). Ensure each has: **icon + title + 1–2 sentence explanation + single CTA** (reuse existing `empty-state-box`). Add short “Why this matters” lines (e.g. “Guests who get a reminder come back 2× more often”). Optionally drive from a small copy/config file for A/B later. |
| **Impact** | Clearer value proposition in-app, fewer “I don’t get it” moments, better activation and feature discovery. |

---

### Step 5 — Mobile-responsive restaurant panel

| | |
|---|---|
| **Problem** | Owners and staff often check dashboard or orders from a phone; current layout is desktop-first and clunky on small screens. |
| **Why it matters for restaurants** | Restaurants are on the floor; mobile-friendly panel means “check orders,” “see today’s revenue,” and “send a comeback message” from a phone. |
| **Implementation** | Responsive pass on key restaurant pages: dashboard, orders, live view. Use existing Tailwind breakpoints; sticky header and bottom nav on small screens; tables become cards or horizontal scroll where needed. Reuse existing motion/toast; ensure touch targets and font sizes are usable. |
| **Impact** | Higher engagement from mobile, better fit for real-world usage, supports “waiter on tablet” and “owner on phone” scenarios. |

---

## Stage 2 — Product intelligence

*Use data to help restaurants earn more and work smarter.*

---

### Step 6 — Guest lifetime value (LTV) and segments

| | |
|---|---|
| **Problem** | Restaurants don’t know which guests are worth more over time or who to prioritize for comeback offers. |
| **Why it matters for restaurants** | Focusing on “high LTV” or “at-risk” guests improves ROI of CRM and retention campaigns. |
| **Implementation** | Derive **guest LTV** from `crm_visits` (e.g. SUM total_amount per guest). Add segments: e.g. “High LTV” (top 20%), “At risk” (no visit in 14+ days, had 2+ visits), “One-time” (1 visit). Expose in CRM list and in campaign targeting (reuse or extend `crm_campaign_repo` segment logic). Optional: simple LTV badge or column in CRM. |
| **Impact** | Smarter retention and campaigns, clearer “who to bring back first,” upsell potential for “advanced segments.” |

---

### Step 7 — AI menu optimization (next level)

| | |
|---|---|
| **Problem** | Menu insights today are “top / low / no sales”; restaurants need concrete actions like “promote this,” “reprice or remove that,” “pair with this.” |
| **Why it matters for restaurants** | Menu is the main profit lever; data-driven suggestions increase revenue and reduce dead stock. |
| **Implementation** | Extend `menu_performance.php`: add **recommendations** (e.g. “Caesar Salad has no sales this week — consider daily special or move higher in menu,” “Pizza Margherita + Cola often ordered together — add combo”). Use order_items + menu_items; optional: margin or cost field later. Surface in dashboard and revenue as “Menu actions” or “Suggested changes” with short rationale. |
| **Impact** | Product feels “intelligent,” guides menu decisions, differentiator vs generic POS. |

---

### Step 8 — Upsell automation rules (trigger-based)

| | |
|---|---|
| **Problem** | Today upsells are “when item A in cart, suggest B.” Restaurants want “after 2 mains, suggest dessert” or “when order > 500 ₽, suggest wine.” |
| **Why it matters for restaurants** | Rule-based upsells increase average check without manual A/B tests; automation scales. |
| **Implementation** | Extend upsell engine: support **triggers** (e.g. category in cart, cart total threshold, item count). Store in DB (e.g. `upsell_triggers` or extend `menu_item_upsells` with trigger_type/trigger_value). In `upsell_repo` or frontend, evaluate triggers before “frequently bought together.” Keep existing logic; add trigger layer on top. |
| **Impact** | Higher average check, more “set and forget” value, clearer upsell analytics by rule type. |

---

### Step 9 — Live order analytics (real-time funnel)

| | |
|---|---|
| **Problem** | Owners see “orders today” but not “how many started checkout, abandoned, or paid” — no funnel view. |
| **Why it matters for restaurants** | Understanding drop-off (e.g. at payment step) helps fix friction and recover revenue. |
| **Implementation** | Track **checkout steps** (e.g. cart viewed → checkout opened → payment selected → order created) in a lightweight events table or session. Dashboard widget: “Today: X started, Y paid, Z abandoned.” No PII; aggregate only. Optional: “Abandoned cart” reminder (e.g. SMS/email) as a later step. |
| **Impact** | Data-driven checkout and UX improvements, potential for abandonment recovery feature. |

---

### Step 10 — Retention automation (scheduled comebacks)

| | |
|---|---|
| **Problem** | “Guest return opportunities” and campaigns exist but require manual “create message”; no automatic “7 days after visit, send offer.” |
| **Why it matters for restaurants** | Automated “we miss you” or “10% off next visit” after N days increases return visits without daily effort. |
| **Implementation** | **Scheduled jobs** (cron): daily scan `crm_guests` + `crm_visits` for “last visit = 7 days ago” (or configurable), create `crm_outbox` entry with template (e.g. comeback_7d). Use existing `crm_schedule_comeback` and templates; add “auto” campaign type or flag. Ensure demo/production: no real send in demo; in production use existing stub or future SMS/email provider. |
| **Impact** | Retention runs in the background, stronger “bring guests back” story, upsell for “automation” tier. |

---

## Stage 3 — Growth engine

*Turn product usage into acquisition and revenue.*

---

### Step 11 — Referral growth loop (rewards and tracking)

| | |
|---|---|
| **Problem** | Referral link exists but there’s no clear incentive for restaurant A to invite B, and no visibility into “who brought whom.” |
| **Why it matters for restaurants** | Restaurants trust other restaurants; referral program can drive low-CAC growth and loyalty. |
| **Implementation** | **Reward** for referrer: e.g. 1 month free or discount when invited restaurant subscribes (store in `referral_conversions` or similar). Dashboard: “You invited X, Y converted,” “Your reward: Z.” Optional: invitee gets first month discount (coupon). Track in existing referral tables; add reward rules and display in restaurant panel. |
| **Impact** | Viral loop, measurable referral ROI, stronger word-of-mouth. |

---

### Step 12 — Usage-based nudges and limits

| | |
|---|---|
| **Problem** | Free/trial users hit limits (e.g. orders, menu items) without a clear path to upgrade; no in-app nudge. |
| **Why it matters for restaurants** | Soft limits + “upgrade to unlock” convert active users instead of hard blocking. |
| **Implementation** | Use existing plan limits (e.g. `orders_month_max`, `menu_items_max`). When approaching limit (e.g. 80%), show **banner or modal**: “You’re at 18/20 menu items. Add more with Pro.” Link to `/restaurant/activate.php`. Optional: “You’ve had 95 orders this month — upgrade for unlimited.” No change to core billing; add checks and one CTA per limit type. |
| **Impact** | Clear upgrade path, higher trial → paid conversion, fair use of free tier. |

---

### Step 13 — Restaurant success metrics (health score)

| | |
|---|---|
| **Problem** | Restaurants don’t know “how am I doing vs my goal” or “what to fix first” in one place. |
| **Why it matters for restaurants** | A simple “health” or “success score” (e.g. menu complete, QR printed, first order, CRM used) drives next best action. |
| **Implementation** | **Health score**: 0–100 from 5–7 signals (e.g. menu items ≥ 5, tables created, QR printed, orders this week, upsell rules, CRM campaign sent). Compute in `dashboard_intel` or new helper; store in cache or on-the-fly. Dashboard block: “Your success score: 72 — add a CRM campaign to reach 85.” Link each gap to the right page. |
| **Impact** | Gamification, clearer “am I set up right,” supports support and success playbooks. |

---

### Step 14 — Acquisition loops (share QR, review prompts)

| | |
|---|---|
| **Problem** | Restaurants don’t have a simple way to ask guests to “share our menu” or “leave a review” after a good experience. |
| **Why it matters for restaurants** | Post-visit touchpoints (share link, Google review) drive new guests and reputation at no ad cost. |
| **Implementation** | **Post-order** (e.g. on thank-you or order_track): optional CTA “Share our menu” (link + copy) and “Leave a review” (link to Google/Yandex). Store review URL in restaurant settings. Optional: “Share” opens native share or copies link; track clicks in analytics. Lightweight: one settings field, one block on order_track or QR thank-you. |
| **Impact** | More organic traffic and reviews, product becomes part of “growth” for the restaurant. |

---

## Stage 4 — Scale & SaaS maturity

*Operate and scale like a serious SaaS.*

---

### Step 15 — Observability, alerts, and reliability

| | |
|---|---|
| **Problem** | As usage grows, outages or slow responses hurt trust; there’s no proactive alerting or clear status. |
| **Why it matters for restaurants** | During service hours, “system down” means lost orders and revenue; reliability is a feature. |
| **Implementation** | **Observability**: ensure errors go to `app_error_logs` or external logger; add simple **uptime/health** endpoint (already have health/ready). **Alerts**: cron or external monitor hits health; on failure, notify (email/Slack). **Status page** (optional): public “All systems operational” or incident log. Optionally: slow-query log, DB connection pooling. No change to business logic; add monitoring and runbooks. |
| **Impact** | Fewer blind spots, faster incident response, higher trust and retention. |

---

## Summary table

| Stage | Step | One-line focus |
|-------|------|----------------|
| 1. Product polish | 1 | First-session activation checklist |
| 1 | 2 | Live order pulse / kitchen view |
| 1 | 3 | Demo-to-trial conversion loop |
| 1 | 4 | Empty-state and onboarding copy |
| 1 | 5 | Mobile-responsive restaurant panel |
| 2. Product intelligence | 6 | Guest LTV and segments |
| 2 | 7 | AI menu optimization (actions) |
| 2 | 8 | Upsell automation (trigger-based rules) |
| 2 | 9 | Live order analytics / funnel |
| 2 | 10 | Retention automation (scheduled comebacks) |
| 3. Growth engine | 11 | Referral rewards and tracking |
| 3 | 12 | Usage-based nudges and limits |
| 3 | 13 | Restaurant success / health score |
| 3 | 14 | Acquisition loops (share QR, reviews) |
| 4. Scale & maturity | 15 | Observability, alerts, reliability |

---

*This roadmap is aligned with the current QR Restaurant SaaS stack (PHP, MySQL, Tailwind, vanilla JS) and existing modules (dashboard, CRM, upsells, referral, onboarding, demo). Prioritization within each stage can be adjusted by impact and effort.*
