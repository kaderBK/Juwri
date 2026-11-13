# Email deliverability (SPF, DKIM, DMARC)

Order confirmations, receipts and password resets are email. If the sending domain is not set up, they land in spam or
are rejected. `php artisan juwri:preflight` warns (never blocks) about the two most common gaps.

## 1. Use one sender domain

Set `MAIL_FROM_ADDRESS` to an address on your own domain, ideally the same domain as `APP_URL` (for example
`orders@your-domain` for `https://your-domain`). Preflight warns when the two differ; that is allowed (for example
`mail.your-domain` or a subdomain) but you then need the records below on the sender domain.

## 2. The three DNS records (on the sender domain)

| Record | Where | What it does | Example TXT value |
|---|---|---|---|
| SPF | `your-domain` | Lists who may send for the domain | `v=spf1 +a +mx include:YOUR-HOST-SPF ~all` |
| DKIM | `selector._domainkey.your-domain` | Signs each message | Given by your mail provider/host |
| DMARC | `_dmarc.your-domain` | Tells receivers what to do on failure | `v=DMARC1; p=none; rua=mailto:you@your-domain` |

* Only **one** SPF record per domain. If you already have one, add the new `include:` to it.
* Start DMARC with `p=none` for two to four weeks, read the reports, then move to `p=quarantine`.
* Use the SPF include and DKIM key your mail provider gives you. Do not copy values from other sites.

## 3. cPanel: Email Deliverability screen

1. cPanel > Email > **Email Deliverability**.
2. Find your domain. Status "Valid" means SPF and DKIM are in place.
3. If it says "Problems Exist", click **Manage** and then **Repair** (cPanel writes the records when the host also runs
   your DNS). If DNS is elsewhere (Cloudflare, registrar), click **Manage**, copy the suggested TXT records and add them
   at that DNS provider by hand.
4. Add the DMARC record yourself (cPanel does not create it).
5. Wait up to an hour, then press the refresh icon on the same screen.

## 4. Shared-host SMTP egress limits

* Many shared hosts block outbound ports 25 and often 465/587 to other servers. Use the host's own mail server
  (`localhost` or the host name with the account's mailbox login) or an HTTPS mail API.
* Hosts cap mail per hour per account (often 100 to 500). A busy day of order mail can hit the cap; messages then
  queue or bounce. Ask the host for the limit and keep it in mind for marketing mail.
* The shared IP is used by other customers; its reputation is not yours. If order mail still goes to spam after SPF,
  DKIM and DMARC are valid, move to a transactional mail provider (any; juwri uses standard SMTP settings).
* Preflight tries the SMTP port and fails the "Mail transport" row if it cannot connect.

## 5. Check it

1. Send a test from the app to a Gmail and an Outlook address you own (do this by hand from Admin; do not script
   sends from CI or from a laptop SMTP).
2. In Gmail open the message > Show original: SPF, DKIM and DMARC should all say PASS.
3. Run `php artisan juwri:preflight`; the "Mail records" and "Mail sender domain" rows should be OK.

## 6. What preflight checks

* **Mail records:** DNS TXT lookup (3 s timeout) for SPF on the sender domain and DMARC on `_dmarc.`. Missing record is
  a WARN. No network or DNS: the row is skipped (WARN "not checked"), never a failure.
* **Mail sender domain:** WARN when the `MAIL_FROM_ADDRESS` domain differs from the `APP_URL` host (a subdomain of it is
  fine).
* DKIM is not checked (the selector name is not known to the app).

## Bounces and spam complaints (P6-249)

When a provider reports a **hard bounce** (the address does not exist) or a **spam complaint**, Juwri adds the address
to a suppression list. Campaigns and weekly digests then skip it. Order mails, receipts and password resets still go
out, and the owner sees "E-mail bounced" or "Marked as spam" next to the customer. The platform admin sees the list at
**Admin > Suppressed e-mails** and can remove an address when the person asks to get mail again.

Point your provider's webhook at `https://YOUR-DOMAIN/webhook/mail/<provider>` and set the matching secret in `.env`.
**If the secret is empty, every request is refused (403)**: nothing is recorded until you set it.

| Provider | Webhook URL | Events to send | `.env` |
|---|---|---|---|
| Amazon SES | `/webhook/mail/ses` (SNS HTTPS subscription) | Bounce, Complaint (SES notifications or a configuration set to SNS) | `SES_SNS_TOPIC_ARN` = the topic ARN. Messages are checked against the AWS SNS signing certificate; the subscription is confirmed automatically. |
| Mailgun | `/webhook/mail/mailgun` | Permanent failure, Spam complaints | `MAILGUN_WEBHOOK_SIGNING_KEY` (Sending > Webhooks > HTTP webhook signing key) |
| Postmark | `https://USER:PASSWORD@YOUR-DOMAIN/webhook/mail/postmark` | Bounce, Spam complaint | `POSTMARK_WEBHOOK_USER`, `POSTMARK_WEBHOOK_PASSWORD` (choose long random values; Postmark sends them as Basic auth) |
| SendGrid | `/webhook/mail/sendgrid` | Bounced, Spam reports; turn on **Signed Event Webhook** | `SENDGRID_WEBHOOK_PUBLIC_KEY` (the verification key SendGrid shows) |

These work whether you send through the provider's SMTP relay or its API. Run `php artisan config:cache` after editing `.env`.

**SMTP-only hosts (cPanel mailbox, your own Postfix) have no webhook.** Bounces come back as e-mails to the sender
mailbox. Read them now and then, and suppress dead addresses by hand: turn off the customer's marketing consent, or ask
the platform admin to add the address (`php artisan tinker`:
`Juwri\Modules\Messaging\Models\EmailSuppression::record('a@b.c', 'bounce', 'manual');`).
