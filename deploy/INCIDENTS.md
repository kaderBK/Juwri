# Incident communications runbook

For whoever runs a juwri installation. For now the team is one person: the owner. This page says what
to check, what to tell people, and when to turn on the maintenance page. It does not name a status-page
provider (that choice is open, see section 9). A personal-data breach follows
`SECURITY-INCIDENT-RUNBOOK.md` instead, which has legal clocks (24/48 hours). If an incident might involve
personal data, open that runbook first and come back here for the messages.

Commands below assume the app is in `core/`. On the Docker profile, run them inside the app container;
on shared hosting or cPanel, run them over SSH or the cPanel terminal.

## 1. Severity levels

| Level | What it means | Examples | First message within | Updates |
|---|---|---|---|---|
| SEV1 | Most or all customers cannot use the service, or money/legal data is at risk | Site down, database down, payments failing for everyone, fiscal (ZATCA) submissions all failing | 15 minutes | every 30 minutes |
| SEV2 | One important feature is broken or slow for many customers | Orders slow, one payment gateway down, queue backed up, e-mail not sending | 30 minutes | every 60 minutes |
| SEV3 | Small or partial problem, workaround exists | One theme broken, one report wrong, one merchant affected | Reply to the people affected | when fixed |

If unsure between two levels, pick the higher one and lower it later.

## 2. Who does what

Owner-only team for now. The owner wears every hat; write the hat down so nothing is skipped.

| Role | Does | Today |
|---|---|---|
| Incident lead | Decides the level, decides to use maintenance mode, approves every message | Owner |
| Fixer | Investigates and fixes | Owner |
| Communicator | Posts the messages in section 4 and keeps to the update times | Owner |
| Recorder | Writes a time-stamped note of what was seen and done (a plain text file is fine) | Owner |

When a second person joins, give them Communicator first. It frees the owner to fix.

## 3. First 15 minutes checklist

Write the start time (UTC) in your notes, then go in this order. Stop at the first thing that is clearly broken.

1. **Is the site up?** Open `/up`. It should answer `{"status":"up"}`. No answer means the web server or PHP is down: check the host panel or container first.
2. **Preflight.** `php artisan ops:preflight` (add `--cloud` on juwri Cloud). Any FAIL line is a lead. `--json` gives a machine-readable copy for your notes.
3. **Logs.** Look at the newest errors in `storage/logs/` (the latest `laravel-*.log`) and the web server error log. Note the first error time. Often it matches a deploy or a config change.
4. **Queue.** `php artisan queue:failed` lists failed jobs. If many pile up in a few minutes, a dependency is down. On Docker or VPS profiles check the worker (supervisor/systemd). On shared hosting the queue runs from cron, so check the cron job ran. Do not mass-retry before you know why they failed.
5. **Database.** Can the app read and write? Check `php artisan migrate:status` answers, and check disk space and connections on the database host. A full disk is a common cause.
6. **Payment webhooks.** In each payment gateway dashboard, look at recent webhook deliveries to us (routes `ipn/{gateway}` and `webhook/{gateway}`). Failures there mean orders paid but not marked paid. List the affected orders for follow-up; do not refund or mark paid by hand until you know what happened.
7. **ZATCA queue.** `php artisan fiscal:zatca-check` (certificate, config, device readiness) and `php artisan fiscal:sla-watch` (documents waiting too long). Waiting documents are not lost: they are sent when ZATCA is reachable again. Note how many and from when.
8. **Decide the level** (section 1), post the first message (section 4), and set your next update time.

Then fix. When you change something, write it in the notes with the time.

## 4. Message templates

Rules: say what people see, say what we are doing, say when the next update is. No blame, no guessing the cause, no promises about the fix time you cannot keep. Replace the parts in `[brackets]`. Customers are people ordering; merchants are restaurants using juwri. Use the same text on the status page (if any), on the site banner, and in e-mail or WhatsApp.

### 4.1 Investigating

**English (customers and merchants)**
> We are looking into a problem with [what people see, e.g. placing orders]. Some people may see errors or slow pages. We will update you by [time, with timezone].

**العربية**
> نعمل حاليا على فحص مشكلة في [ما يلاحظه المستخدمون، مثل إرسال الطلبات]. قد يرى بعض المستخدمين أخطاء أو بطئا في الصفحات. سنوافيكم بتحديث قبل [الوقت مع المنطقة الزمنية].

### 4.2 Identified

**English**
> We found the cause of the problem with [what people see] and we are working on the fix. [One sentence workaround if there is one, e.g. "Please try again in a few minutes."] Next update by [time].

**العربية**
> تعرفنا على سبب المشكلة في [ما يلاحظه المستخدمون] ونعمل على إصلاحها. [جملة واحدة عن حل بديل إن وجد، مثل: "يرجى المحاولة مرة أخرى بعد دقائق."] التحديث التالي قبل [الوقت].

### 4.3 Monitoring

**English**
> A fix is in place for [what people saw]. Things should be working again. We are watching closely to be sure. If you still see a problem, please contact [support address].

**العربية**
> تم تطبيق إصلاح لمشكلة [ما لاحظه المستخدمون]. يفترض أن تعمل الخدمة الآن بشكل طبيعي، ونواصل المتابعة للتأكد. إذا استمرت المشكلة لديكم يرجى التواصل معنا على [عنوان الدعم].

### 4.4 Resolved

**English**
> The problem with [what people saw] is fixed. It started at [time] and ended at [time]. [Anything people must do, or "You do not need to do anything."] We are sorry for the trouble. We will share what happened and what we change within [number] days.

**العربية**
> تم حل مشكلة [ما لاحظه المستخدمون]. بدأت في [الوقت] وانتهت في [الوقت]. [أي إجراء مطلوب من المستخدم، أو: "لا يلزمكم أي إجراء."] نعتذر عن الإزعاج. سنشارككم ما حدث وما سنغيره خلال [عدد] أيام.

### 4.5 Payment outage

Use when card or wallet payments fail. Cash and other methods keep working unless you say otherwise.

**English, to customers (show on checkout)**
> Online payment is not working right now. [Cash on delivery / pay at the restaurant] still works. Please do not pay twice. If you were charged and your order does not show as paid, it will be fixed and you will not be charged for it twice.

**English, to merchants**
> Online payments through [gateway name] are failing since [time]. Orders already placed are safe. We are checking every payment that did not complete and will list the ones to review for you by [time]. Please do not refund or re-charge these orders by hand until we confirm. You can take cash orders as usual.

**العربية، للعملاء (تظهر عند الدفع)**
> الدفع الإلكتروني لا يعمل حاليا. [الدفع عند الاستلام / الدفع في المطعم] ما زال متاحا. يرجى عدم الدفع مرتين. إذا تم الخصم من حسابك ولم يظهر الطلب كمدفوع فسنصحح ذلك ولن يتم خصم المبلغ منك مرتين.

**العربية، للتجار**
> الدفع الإلكتروني عبر [اسم بوابة الدفع] يفشل منذ [الوقت]. الطلبات التي تم إرسالها محفوظة. نراجع كل عملية دفع لم تكتمل وسنرسل لكم قائمة الطلبات التي تحتاج مراجعة قبل [الوقت]. يرجى عدم رد المبلغ أو إعادة التحصيل يدويا لهذه الطلبات حتى نؤكد لكم. يمكنكم متابعة استقبال الطلبات النقدية كالمعتاد.

### 4.6 ZATCA outage (e-invoicing, Saudi Arabia)

Use when invoices cannot be reported or cleared to ZATCA, whether the cause is on our side or theirs.

**English, to merchants**
> E-invoices for ZATCA are delayed since [time]. [Cause in one plain sentence, e.g. "The ZATCA service is not answering."] Your sales and receipts are not affected. Invoices waiting to be sent are saved and will be sent automatically when the connection works again. You do not need to enter anything again. We will tell you when the queue is empty. Next update by [time].

**العربية، للتجار**
> الفواتير الإلكترونية الخاصة بهيئة الزكاة والضريبة والجمارك متأخرة منذ [الوقت]. [السبب بجملة بسيطة، مثل: "خدمة الهيئة لا تستجيب."] مبيعاتكم وإيصالاتكم لا تتأثر. الفواتير المنتظرة محفوظة وسيتم إرسالها تلقائيا عند عودة الاتصال. لا حاجة لإدخال أي شيء مرة أخرى. سنخبركم عند تفريغ قائمة الانتظار. التحديث التالي قبل [الوقت].

Customers normally need no ZATCA message. If a merchant asks, tell them the invoice is saved and will be sent shortly, and check the reporting deadline for their invoice type with the owner or an accountant before promising anything.

## 5. When to use maintenance mode

Maintenance mode shows the static maintenance page (P6-112) instead of the app. It stops orders, so use it only when the app should not serve traffic.

Use it when:
- a database migration or restore is running and the app would write half-finished data;
- a bug is corrupting data (wrong totals, double charges, duplicate invoices) and each minute makes more of it;
- the database or storage is down and every page would show an error anyway;
- a security fix must go in before people can use the app again.

Do not use it for: one broken feature (turn that feature off or leave a notice), a slow site, a single merchant's problem, or an outage that is not ours (a gateway or ZATCA being down: show the notice from 4.5 or 4.6 instead).

How: use the command and steps P6-112 documents once merged (until then, do not rely on it). Before you turn it on, post an Investigating or Identified message so people know why. After you turn it off, check `/up` and one real order flow, then post Monitoring.

## 6. After the incident

Within 3 working days for SEV1 and SEV2, send the Resolved follow-up promised in 4.4 (what happened, how long, what we change). Fill in the review in section 7.

## 7. Post-incident review template

Copy this into a new file named `INC-YYYY-NNN.md`. No blame: write what happened and what the system allowed, not who was wrong.

```
# INC-YYYY-NNN  [short title]

Level: SEV[1-3]      Date: [YYYY-MM-DD]
Started (UTC): [time]   Detected (UTC): [time]   Resolved (UTC): [time]
Total length: [hours:minutes]   Customers/merchants affected: [number or "all"]
Money or legal data affected: [yes/no, details]

## Summary
[3 sentences: what people saw, why, how it ended]

## Timeline (UTC)
- [time] [what was seen or done]

## Cause
[Plain words. The deeper cause too, not only the trigger.]

## What went well
-

## What went badly
- [include: how we found out (customer or alert?), how long to first message]

## Messages sent
[When each template was used, in which languages and channels]

## Actions
| Action | Owner | Due | Done |
|---|---|---|---|
| [fix so it cannot happen again] | | | |
| [better alert or check] | | | |
| [runbook fix] | | | |

## Did we need to tell anyone by law or contract?
[Personal data breach, payment partner, ZATCA, SLA credits. Link the decision.]
```

## 8. Keep this page true

After each incident, change this page if a step was missing or wrong. Check the commands in section 3 still exist after big releases (`ops:preflight`, `queue:failed`, `fiscal:zatca-check`, `fiscal:sla-watch`).

## 9. Status page provider (OWNER-DECIDES)

Not decided. The app only needs one thing from the choice: a public URL. Set it as `STATUS_PAGE_URL` in `.env`; when set, a "System status" link shows in the site footer (and, once P6-112 lands, on the 500/503 pages). When empty, nothing shows. The app does not send data to the provider.

| Option | Type | Cost (check current pricing) | Good for | Needs from the owner | Watch out for |
|---|---|---|---|---|---|
| Uptime Kuma | Self-hosted, open source | Server cost only | Full control, data stays with us, public status page built in | A small server or VPS (not the main app host, or it dies with it), Docker or Node, a sub-domain (e.g. `status.<domain>`), TLS, updates | Not possible on plain shared hosting (needs a long-running process); we run and patch it; if hosted next to the app, it fails together with the app |
| Atlassian Statuspage | Hosted | Paid, free tier is small | Well known, subscriber e-mail/SMS, many integrations | Account, payment method, optional custom domain (CNAME), logo | Cost grows with subscribers; custom domain on higher plans |
| Better Stack (Better Uptime) | Hosted, monitoring plus status page | Free tier and paid plans | Monitoring and status page in one, on-call alerts | Account, monitors for `/up`, optional custom domain (CNAME) | Check which features are on the free plan; phone alerts cost extra |
| Instatus | Hosted | Free tier and paid plans | Quick and simple status page, subscribers | Account, optional custom domain (CNAME) | Check free-plan limits (subscribers, custom domain) |

What every option needs from us, whichever is chosen:
1. A domain or sub-domain for the page (recommended: `status.<our domain>`), with DNS access to add a CNAME or A record.
2. A monitor that calls `/up` every minute from outside our own network.
3. An alert channel that reaches the owner outside e-mail from the same server (phone push or SMS).
4. The page text in English and Arabic (copy sections 4.1 to 4.6).
5. The final URL, set as `STATUS_PAGE_URL`.

Open question for the owner: which option, and which domain. Until then use section 4 messages by e-mail and WhatsApp.
