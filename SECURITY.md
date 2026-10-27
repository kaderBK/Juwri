# Security policy

## Reporting a vulnerability
Use GitHub's private vulnerability reporting: open the repository's **Security** tab and choose
**Report a vulnerability**, with steps to reproduce. Do not open a public issue, and do not post details
anywhere public before a fix is out. This is the only reporting channel; we do not publish an email address
for vulnerability reports.
We acknowledge within 2 working days and aim to fix critical issues within 7 days and high ones
within 30. We credit reporters who want it. A bug bounty will be announced separately.

## Scope
In scope: this repository and juwri Cloud (*.juwri.com). Out of scope: denial-of-service tests,
social engineering, spam, physical attacks, and third-party services.

## Safe harbour
If you act in good faith, follow this policy, avoid privacy violations and service disruption,
and give us reasonable time to fix the issue before disclosure, JUWRI LTD will not take legal
action against you for your research.

## Supported versions
The latest minor release and the previous one receive security fixes. juwri Cloud is patched
first; self-hosted releases follow the same day for critical issues.

## For self-hosters
Subscribe to security announcements at https://juwri.com/security. Keep `APP_KEY` secret and backed up: stored
credentials are encrypted with it and cannot be recovered if it is lost.
