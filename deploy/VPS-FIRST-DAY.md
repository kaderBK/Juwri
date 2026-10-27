# VPS first-day checklist (WHM / cPanel)

For the owner. This covers the things nobody can test in a container: they happen in WHM, in DNS and at your hosting
provider. Do them in this order. Tick each box only when the "How to check" line gives the answer shown. The install
itself is `deploy/HOSTING.md` section 3; the worker units are in `deploy/systemd/` (see section 3 below).

Use your own account name where this says `ACCOUNT` and your own domain where it says `YOUR-DOMAIN`.

## 1. AutoSSL (the padlock)

- [ ] WHM > SSL/TLS > Manage AutoSSL: pick a provider (cPanel (powered by Sectigo) or Let's Encrypt), tick your
      account, save. Click "Run AutoSSL For All Users" once.
- [ ] DNS for YOUR-DOMAIN and www.YOUR-DOMAIN already points at the server's IP. AutoSSL cannot issue a certificate
      before that.
- How to check: open `https://YOUR-DOMAIN` (padlock, no warning). On the server:
  `echo | openssl s_client -connect YOUR-DOMAIN:443 -servername YOUR-DOMAIN 2>/dev/null | openssl x509 -noout -issuer -dates`
  shows the issuer and a `notAfter` date about 90 days away. WHM > Manage AutoSSL > Logs shows "Success" for the account.
- [ ] `APP_URL` in `core/.env` starts with `https://`, and `curl -sI http://YOUR-DOMAIN | head -1` answers a redirect
      (301 or 302) to https.

## 2. PHP 8.4 (EasyApache)

- [ ] WHM > Software > EasyApache 4 > Customize: add **ea-php84** and these extensions: mysqlnd, mbstring, gd, zip,
      curl, xml, sodium, fileinfo, iconv, opcache (intl and bcmath are optional). Provision.
- [ ] WHM > MultiPHP Manager: set YOUR-DOMAIN to ea-php84 with PHP-FPM. MultiPHP INI Editor: `memory_limit` 512M,
      `max_execution_time` 60, `upload_max_filesize` and `post_max_size` 64M.
- How to check: `/opt/cpanel/ea-php84/root/usr/bin/php -v` says 8.4.x;
  `/opt/cpanel/ea-php84/root/usr/bin/php -m` lists all of the extensions above;
  `cd /home/ACCOUNT/juwri/core && /opt/cpanel/ea-php84/root/usr/bin/php artisan juwri:preflight --profile=cpanel-vps`
  has no FAIL row for PHP or extensions (section 9 below).

## 3. Workers and the scheduler: systemd units (alternative to supervisor)

Pick supervisor (`deploy/HOSTING.md` section 3 step 5) **or** these units, never both. As root:

```
bash /home/ACCOUNT/juwri/deploy/systemd/install.sh ACCOUNT
```

This copies `juwri-queue@.service` (instances `default` and `heavy`) and, unless you pass `--no-scheduler`,
`juwri-scheduler.service` + `.timer`, fills in the account, the `core` folder and the ea-php84 binary, and starts them.
If you use the timer, remove the crontab line from HOSTING.md step 4 (one scheduler only).

- [ ] `systemctl status juwri-queue@default juwri-queue@heavy` both "active (running)".
- [ ] `systemctl list-timers juwri-scheduler.timer` shows a NEXT time within a minute.
- [ ] After every deploy: `systemctl reload 'juwri-queue@*'` (runs `queue:restart`; each worker finishes its job, leaves,
      and systemd starts it again with the new code).
- [ ] `journalctl -u 'juwri-queue@*' -f` shows jobs processing.
- A reboot test: reboot the server once in a quiet hour; both workers and the timer come back by themselves.

## 4. Firewall (CSF) and server hardening

- [ ] Open to the world, inbound: 80 and 443 (web), 25/465/587 and 993 (mail, only if this server hosts your mail),
      and your SSH port (see below). Everything else closed. In WHM > Plugins > ConfigServer Security & Firewall >
      Firewall Configuration, `TCP_IN` should contain only those; `UDP_IN` only 53 (DNS) if this server runs DNS.
- [ ] MySQL port 3306 is **not** in `TCP_IN`. The app talks to MariaDB over localhost.
- [ ] Redis (6379), if you installed it, is **not** in `TCP_IN` and listens on 127.0.0.1 only.
- [ ] Outbound: leave `TCP_OUT` open for 443 (payment gateways, ZATCA Fatoora, WhatsApp, SMS), 587/465/25 (mail) and 53.
      If you restrict outbound, test that 443 works (`curl -sI https://api.stripe.com | head -1`).
- [ ] SSH: key login, root login off or key-only, and in CSF "SSH" tracking on (LF_SSHD) so brute force gets blocked.
      Your own IP is in `csf.allow`.
- [ ] WHM > Security Center > cPHulk Brute Force Protection is enabled.
- How to check: from another machine, `nmap -Pn -p 22,80,443,3306,6379 YOUR-SERVER-IP` shows 80 and 443 open (and
  your SSH port) and 3306 and 6379 filtered or closed. On the server `csf -l | head -40` lists the rules.

## 5. Mail DNS (so order mail reaches inboxes)

- [ ] **SPF**: a TXT record on YOUR-DOMAIN, e.g. `v=spf1 +a +mx +ip4:SERVER-IP ~all` (add your mail provider's include if
      you send through one). Only one SPF record per domain.
- [ ] **DKIM**: WHM > Email > Email Deliverability > YOUR-DOMAIN > Repair (or Manage). It shows DKIM valid. If you send
      through an outside provider (SES, Mailgun, Brevo) publish their DKIM records instead.
- [ ] **DMARC**: a TXT record `_dmarc.YOUR-DOMAIN` = `v=DMARC1; p=none; rua=mailto:you@YOUR-DOMAIN`. Start at `p=none`,
      read the reports for two weeks, then move to `quarantine`.
- [ ] **Reverse DNS (PTR)** for the server IP names the mail host (your hosting provider sets this on request). The mail
      host name resolves back to the same IP.
- [ ] The server IP is not on a blocklist (check mxtoolbox.com/blacklists).
- [ ] `core/.env`: `MAIL_MAILER=smtp` (or your provider), the host, port, user, password, and
      `MAIL_FROM_ADDRESS` on YOUR-DOMAIN. Then `php artisan config:cache` and restart the workers.
- How to check: send a test mail from the app (Admin > Settings > Email, or place a test order) to a Gmail address.
  Open the message > "Show original": SPF, DKIM and DMARC all say PASS. mail-tester.com should score 9 or more.
  The order confirmation is a queued job, so this also proves the workers.

## 6. Backups and the off-box copy

- [ ] The app's own daily dump runs (`platform:db-backup`, from the scheduler). It writes to
      `core/storage/app/backups/platform/` and keeps `BACKUP_KEEP_DAYS` days (default 30).
- [ ] Off-box copy: set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (any S3-compatible
      provider; add `AWS_ENDPOINT` for non-AWS) and `BACKUP_S3_SSE=AES256`. The bucket is private, in a different
      provider or region than the server, and the key can write but not delete.
- [ ] WHM > Backup > Backup Configuration: backups enabled, daily, 7 daily and 4 weekly kept, **a remote destination**
      (SFTP, S3 or another server), not only the server's own disk. Click "Validate" on the destination.
- [ ] Keep outside the server, in a password manager: `APP_KEY`, the ZATCA private key and certificate, VAPID keys, any
      signing key. A backup without `APP_KEY` cannot decrypt stored secrets and 2FA.
- How to check: run `php artisan platform:db-backup` by hand; a new file appears in the folder above and in the bucket
  (look in the provider's console, check the date and size). WHM > Backup > Backup Restoration lists last night's file.
  **Restore one into an empty database this week** (see the backup and restore guide); an untested backup is not a backup.

## 7. Monitoring and the status page

- [ ] External uptime check on `https://YOUR-DOMAIN/up` (UptimeRobot, Better Stack or similar), every 1 to 5 minutes,
      alerting you by email and phone. It must expect HTTP 200 and the text `up`.
- [ ] A public status page (the same tool usually has one) pointing at that check, so customers have somewhere to look.
- [ ] Admin > System shows the scheduler heartbeat and a live queue worker within two minutes of start. If the queue
      worker line is red, the units from section 3 are not running.
- [ ] Optional: Laravel Pulse (`PULSE_ENABLED=true`, see `deploy/PROD-HARDENING-CHECKLIST.md`).
- [ ] Disk and memory: WHM > Server Status > Service Status is green; a disk alert at 80% is set in WHM > Server
      Configuration > Tweak Settings > Notifications (or in your monitoring tool).
- [ ] An alert contact is set: `ALERT_*` variables in `core/.env` (see `core/.env.example`) so failed jobs and a stale
      backup reach you.
- How to check: pause the check (or `systemctl stop juwri-queue@default` for a minute): you get the alert, then restore it.
  A short stop is safe: jobs wait in the queue and run when the worker is back.

## 8. Log rotation

- [ ] Laravel app log: nothing to do. It writes `core/storage/logs/laravel-DATE.log` and keeps 14 days
      (`LOG_CHANNEL=stack`, `LOG_LEVEL=warning` in production).
- [ ] Worker output: with systemd it goes to the journal. Cap it: set `SystemMaxUse=500M` and `MaxRetentionSec=1month`
      in `/etc/systemd/journald.conf`, then `systemctl restart systemd-journald`. With supervisor the shipped config caps
      `worker.log` at 50 MB x 10.
- [ ] Apache, PHP-FPM and mail logs: cPanel rotates the web logs itself (WHM > Tweak Settings > Logging: archive
      logs after processing, remove archived logs after the number of days you choose). Check `/etc/logrotate.d/` has
      entries for `exim` and `php-fpm`, and for any extra log files you add.
- [ ] MariaDB: `slow_query_log=1` is optional; if you turn it on, rotate it (`/etc/logrotate.d/mysql`).
- How to check: `du -sh /home/ACCOUNT/juwri/core/storage/logs /var/log /var/log/journal` once now and again after a
  week. Nothing grows without a limit; `df -h` stays under 70%.

## 9. First `juwri:preflight` run

As the account user (never root):

```
cd /home/ACCOUNT/juwri/core
/opt/cpanel/ea-php84/root/usr/bin/php artisan juwri:preflight --profile=cpanel-vps
```

- [ ] Exit code 0 (`echo $?`) and no FAIL rows. WARN rows are read, not ignored: "mail=log" means mail still goes
      nowhere (fix in section 5); a "web PHP unchecked" warning goes away when you run it without `--no-web` on the
      live domain.
- [ ] Then `php artisan ops:preflight`: no failing check.
- [ ] `php artisan fiscal:zatca-check --strict` once ZATCA onboarding is done (Saudi stores only).
- [ ] Run this section again after every PHP or EasyApache change, and write the date here: ____________.

## Done when

Every box above is ticked, `https://YOUR-DOMAIN/up` is green in your monitor, a test order produced a confirmation
email that passed SPF, DKIM and DMARC, and last night's backup is in the off-box bucket.
