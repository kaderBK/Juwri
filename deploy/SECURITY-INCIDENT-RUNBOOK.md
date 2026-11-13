# Security incident runbook (personal-data breach)

For the people who run a juwri installation. On juwri Cloud this backs the promises in the
Subscription Terms (9.2), the Data Processing Addendum (6.1, 6.2, Schedule 4 D2) and the security
page (section 8): **affected customers are notified without undue delay and in any case within
48 hours of confirming that their personal data is affected — 24 hours for a customer
established in Kuwait.** The clock starts when the breach is *confirmed* (reasonable certainty that
customer personal data was affected), not when the investigation ends. Never delay a notice
because the investigation is still open.

## 0. Roles

- **Incident lead** — owns the timeline below and the final decision to notify.
- **Engineer on call** — contains the incident and collects the facts.
- **Recorder** — keeps the incident log (time-stamped, facts only) in the private incident folder.

## 1. First hour — contain and record

1. Open an incident log: reference `INC-YYYY-NNN`, who found it, when, how.
2. Contain: revoke exposed credentials and tokens, rotate keys, block the access path, take
   affected services offline if needed.
3. Preserve evidence: copy logs (web, queue, database audit, `activity_logs`), do not delete them.
4. Keep the latest backups: confirm `platform:db-backup` ran (Admin → Backup health) and copy the
   most recent dump somewhere it will not be pruned.

## 2. Confirm — start the clock

Decide whether customer personal data was (or is reasonably certain to have been) accessed,
disclosed, altered, lost or destroyed. Write down the **confirmation time (UTC)**. From that moment:

| Customer | Notice deadline |
|---|---|
| Established in Kuwait (billing country or owner country KW) | 24 hours |
| Everyone else | 48 hours |

## 3. Find the affected customers and their deadlines

The customer is the **account** (its owner's email). Scope the incident to stores, accounts, or all
accounts, and list who must be told and by when — this is a dry run, nothing is sent:

```
php artisan security:breach-notice INC-2026-001 --store=12 --store=40 --confirmed-at=2026-10-04T10:00:00Z
php artisan security:breach-notice INC-2026-001 --account=7 --confirmed-at=...
php artisan security:breach-notice INC-2026-001 --all --confirmed-at=...
```

The table shows each account, its owner and email, its country (`(24 h)` marks Kuwait), the notice
deadline in UTC, the reseller (if the account belongs to one) and whether it was already notified.
The command warns about anyone already past the deadline.

Accounts that belong to a **reseller**: also notify the reseller under the data-protection terms of
the Reseller Agreement (the reseller is the store's provider).

## 4. Send the notice

The notice must say, as far as is known (DPA 6.2): what happened, which categories of data (and
roughly how many people and records), what has been done and what the customer should do, and whom
to contact. Write it in plain words; the email frame is translated into the owner's language, the
details you write are sent as written.

```
php artisan security:breach-notice INC-2026-001 --store=12 --store=40 \
  --confirmed-at=2026-10-04T10:00:00Z --send \
  --summary="An export link was publicly reachable between 1 and 3 October." \
  --data="Names and phone numbers of about 230 customers of your store; no payment data." \
  --measures="We disabled the link on 3 October. You may need to tell your customers and your regulator." \
  --contact=security@juwri.com
```

- Every notice is recorded in the `breach_notices` table (incident, account, email, country, notice
  period, confirmation time, deadline, time sent) — the evidence that the deadline was met.
- A copy of each notice goes to `BREACH_NOTICE_BCC` (juwri Cloud: `security@juwri.com`, DPA 6.2).
- Re-running the same command is safe: customers already notified for the incident are skipped,
  so a run that partly failed (the command lists the failures) can simply be repeated.
- If a deadline was missed, send anyway and record the reason in the incident log.

## 5. Follow-up

- Send updates as facts change (a new incident reference suffix, e.g. `INC-2026-001-U1`, so the
  record keeps each notice).
- Give customers what they need for their own notices to regulators and to their customers
  (Saudi Arabia PDPL, UAE PDPL, UK GDPR, Kuwait CITRA rules — each customer decides its own duty).
- UK GDPR: as a processor juwri notifies its customers; where juwri is itself a controller (its own
  account data), the ICO must be told within 72 hours of awareness unless the breach is unlikely
  to result in a risk.
- Close the incident: root cause, what changed, and the notices sent (`breach_notices` rows).
  Share the summary of changes with affected customers (security page 8).

## Settings

| Env | Default | juwri Cloud |
|---|---|---|
| `BREACH_NOTICE_HOURS` | 48 | 48 |
| `BREACH_NOTICE_HOURS_KUWAIT` | 24 | 24 |
| `BREACH_NOTICE_BCC` | empty | `security@juwri.com` |
