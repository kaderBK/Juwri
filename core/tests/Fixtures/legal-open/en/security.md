# Security at juwri

Version 1.0 · Effective 4 October 2026

---

JUWRI LTD (company number 14363043, registered in England and Wales) builds juwri, open-source
software for restaurants, cafés, shops and other businesses, and runs juwri Cloud. Security
researchers help keep our customers and their customers safe. This page explains how to report a
vulnerability, what you can expect from us, and how we protect juwri Cloud.

## 1. Report a vulnerability

- **Private report:** use {{SECURITY_REPORT}} ("Report a vulnerability" under the Security tab). We do not take
  vulnerability reports by email.
- **Languages:** English or Arabic.

Please don't report security issues in public GitHub issues, forums or social media.

A good report includes:
- the affected product and version (juwri Community, juwri Enterprise, or juwri Cloud), or the URL;
- what the vulnerability is and what an attacker could do with it;
- step-by-step instructions to reproduce it, and a proof of concept if you have one;
- any accounts, store IDs or data you accessed while testing (so we can check and clean up);
- how you'd like to be credited, if at all.

## 2. What you can expect from us

| Step | Our target |
|---|---|
| Acknowledge your report | within 2 working days (UK) |
| First assessment and severity (CVSS v4) | within 5 working days |
| Fix critical issues | within 7 days of confirming them |
| Fix high-severity issues | within 30 days |
| Fix medium and low issues | within 90 days, or in the next planned release |
| Keep you updated | at least every 14 days until it's fixed |

We'll tell you when the fix is released. For issues in the open-source code we publish a security
advisory (GitHub Security Advisory) and request a CVE identifier where appropriate, crediting you
unless you ask us not to.

**Coordinated disclosure.** Please give us reasonable time to fix the issue before you share it
publicly. We ask for up to 90 days from your report, or until a fix is released, whichever comes
first. If a vulnerability is being actively exploited, we may agree a shorter timeline with you. If
we need longer, we'll explain why and agree a date with you.

**Thanks.** We list researchers who report valid issues in our Hall of Fame at https://juwri.com/security/thanks (with your permission). We do not currently run a paid bug
bounty; if we launch one, we'll announce it on this page.

## 3. Scope

**In scope**
- The juwri open-source code (Community Edition) at {{FILL:github_url}}, latest release and
  the main branch.
- The juwri Enterprise package, current supported versions.
- juwri Cloud: juwri.com and its subdomains (*.juwri.com), including the owner dashboard,
  storefronts we host, and our public APIs.
- Our mobile and point-of-sale apps, when published under the JUWRI LTD name.

**Test only on what you own.** Use your own juwri installation, or a free juwri Cloud account you
created for testing. Do not test against other people's stores, customers or accounts.

**Out of scope**
- Stores and websites run by our customers on their own servers or domains. Report those to the
  store owner.
- Websites or services of third parties we connect to (for example payment gateways, delivery
  apps, messaging providers), even if you found them through juwri. Report those to the provider.
- Denial-of-service or load testing, spam, social engineering or phishing of our staff or
  customers, and physical attacks.
- Automated scanner output without a demonstrated security impact.
- Issues that need an already-compromised device or browser, or physical access to a device.
- Missing best-practice settings without a practical exploit, for example: missing security
  headers, cookie flags on non-sensitive cookies, clickjacking on pages without sensitive actions,
  logout CSRF, SPF/DKIM/DMARC records, TLS cipher preferences, version banners.
- Rate-limit findings on non-sensitive endpoints, and username or email enumeration without further
  impact.
- Vulnerabilities in outdated, unsupported versions of juwri.

## 4. Rules for testing

When testing, please:
- only access the minimum data needed to show the issue. If you reach personal data (for example a
  customer's name, phone number, address or order history), stop, don't copy or keep it, and tell
  us in your report;
- never change or delete data that isn't yours, and don't keep access longer than needed;
- don't degrade our service: no denial-of-service, and keep automated tools under 5 requests per
  second against juwri Cloud;
- don't use a vulnerability to pivot to other systems, install persistent access, or exfiltrate data;
- don't demand payment or other benefits in exchange for not disclosing an issue;
- keep the details confidential until the issue is fixed or the agreed disclosure date;
- follow the law.

## 5. Safe harbour

If you make a good-faith effort to follow this policy while researching and reporting a
vulnerability, JUWRI LTD:
- considers your research to be authorised by us, as far as we are able to authorise it;
- will not start or support legal action against you, or report you to law enforcement, for that
  research;
- will not bring a claim against you for breaching our terms of service in the course of that
  research, where the breach was necessary for it;
- will work with you to understand and fix the issue quickly.

We can only give this assurance for JUWRI LTD's own systems and rights. We cannot authorise testing
of systems owned by others (including our customers and the third-party services in section 3), and
we cannot bind law enforcement or other people. If a third party takes legal action against you for
research that followed this policy, we'll make it known that your actions were conducted under this
policy.

If you're unsure whether something is allowed, ask us before you test, through {{SECURITY_REPORT}}.

## 6. Supported versions

| Product | Receives security fixes |
|---|---|
| juwri Cloud | Always the current version; we patch it first. |
| juwri Community and Enterprise (self-hosted) | The latest minor release and the one before it. Critical fixes for self-hosted releases follow the Cloud fix on the same day where possible. |
| Older versions | No security fixes. Please upgrade. |

**Self-hosters:** follow security announcements at https://juwri.com/security/announcements, and
use GitHub's "Watch → Security alerts" on the repository. Keep your `APP_KEY` secret and backed up:
stored credentials are encrypted with it and can't be recovered if it's lost.

## 7. How we protect juwri Cloud

- **Hosting:** juwri Cloud runs on {{FILL:hosting_provider}} in one hosting region for all
  customers: {{FILL:hosting_region}}.
- **Encryption:** all traffic uses HTTPS (TLS 1.2 or later). Data is encrypted at rest by our
  hosting provider. {{VERIFY:encryption_at_rest}}
  Payment-gateway keys and other secrets that stores enter are additionally encrypted in our
  database with application-level encryption.
- **Payments:** card details are entered on the payment provider's own pages or fields; juwri does
  not store full card numbers. juwri is not itself a PCI DSS Level 1 service provider; each payment
  provider holds its own PCI certification.
- **Tenant isolation:** each business's data is separated by account and store at the application
  level, and we run automated tests that check that one store can't read another's data.
- **Access control:** we limit staff access to production to people who need it, and remove it when
  they leave. We aim to protect that access with multi-factor authentication and to log it.
- **Customer accounts:** two-factor authentication and passkeys for owners and staff; staff PIN
  login for point-of-sale devices. {{VERIFY:auth_methods}}
- **Backups:** we keep encrypted backups, taken daily and kept for 30 days, and we aim to test
  restoring them regularly. {{VERIFY:backups}}
- **Development:** we review and test changes before release, use automated security checks, and
  monitor dependencies for known vulnerabilities.
- **Independent testing:** we will not describe an independent penetration test or audit as
  completed until it has been.
- **Certifications:** we do not currently hold ISO/IEC 27001 or SOC 2 certification. We will not
  claim a certification until we hold it.
- **Sub-processors:** the list of companies that process data for juwri Cloud is at
  https://juwri.com/legal/subprocessors.

## 8. Security incidents

If we become aware of a security incident affecting customer data in juwri Cloud, we will:
- notify affected customers without undue delay, and in any case within 48 hours of confirming
  that their personal data is affected, so they can meet their own legal deadlines;
- give them the information they need to notify regulators and their customers;
- investigate, contain the incident, and share what we've changed to prevent it happening again.

To report a suspected incident affecting your own store, email security@juwri.com.

## 9. security.txt

Our machine-readable contact file is at https://juwri.com/.well-known/security.txt (RFC 9116):

```
Contact: https://juwri.com/security
Expires: 2027-10-01T00:00:00.000Z
Encryption: https://juwri.com/.well-known/pgp-key.txt
Policy: https://juwri.com/security
Acknowledgments: https://juwri.com/security/thanks
Preferred-Languages: en, ar
Canonical: https://juwri.com/.well-known/security.txt
```

We may update this policy; changes apply to reports received after the update.

*Last updated: 4 October 2026 · Version 1.0*
