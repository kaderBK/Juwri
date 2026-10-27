# Origins, store addresses and TLS (P1-20, J28)

Host names below are placeholders; every one is configuration (`config/hosts.php`).

## Origins

| Origin | Env | Serves | Session cookie (split mode) |
|---|---|---|---|
| Marketing | `MARKETING_URL` | marketing pages; dashboard/login links go to the app host | `__Host-site_marketing` (no dashboard login) |
| App | `APP_URL` | owner/staff dashboard + login; refuses superadmin logins | `__Host-site_app` |
| Admin | `APP_ADMIN_URL` | superadmin only: own login, 2FA enforced, shorter session, optional `ADMIN_IP_ALLOWLIST` | `__Host-site_admin` |
| Storefront | `STOREFRONT_URL` | every store address; never dashboard, admin or login routes | `__Host-site_store` (one per store host) |

`HOST_MODE=single` (default) keeps today's single host; the superadmin security page shows a warning.
`HOST_MODE=split` turns the table on. `__Host-` is used only when cookies are Secure (HTTPS / `SESSION_SECURE_COOKIE`).
Do not set `SESSION_DOMAIN` in split mode (it is ignored): cookies must stay host-only.
Single mode uses one session cookie, `site_session` (`SESSION_COOKIE` overrides). Cookie names are brand-neutral because
a reseller's white-label host shows them too (P1-12; the earlier `juwri_session` / `juwri_*` names were never released —
an instance upgraded from a build with the old names logs every user out once).

## Store addresses

- Path form `STOREFRONT_URL/{slug}` (always exists), platform subdomain `{label}.<storefront host>` (a
  `store_domains` row, type `subdomain`, reserved labels refused), custom domain (type `custom`, live only once
  the TXT token is found; `DOMAIN_VERIFICATION=cname_or_txt` restores CNAME-only proof).
- One primary per store; every other form answers GET/HEAD with 301 to the same page on the primary
  (`STORE_PRIMARY_REDIRECT=false` turns that off). Signed-in dashboard users are not redirected (previews).
- Build links with `store_url($store, $path)`; canonical and hreflang already use the primary.

## TLS

- Platform hosts: one certificate each (or `*.<apex>`).
- Platform subdomains: a wildcard `*.<storefront host>` certificate, issued with the DNS-01 challenge
  (HTTP-01 cannot issue wildcards).
- Custom domains (Community): Caddy on-demand TLS, approving only hosts the platform serves:

  ```caddy
  {
      on_demand_tls {
          ask https://app.example.com/api/tls/ask?token={env.TLS_ASK_TOKEN}
      }
  }
  https:// {
      tls { on_demand }
      reverse_proxy app:8080
  }
  ```

  `GET /api/tls/ask?domain=<host>` answers 200 only for a platform host, a verified store domain (plus the
  `www.` alias of a verified custom domain) or an active reseller domain, else 404. Set `TLS_ASK_TOKEN` to require
  the shared secret. Cloud uses Cloudflare for SaaS custom hostnames instead (automated multi-domain TLS
  operations are Cloud-only).
- Apple Pay domain verification is per custom domain (serve the merchant file on each verified host) — not
  automated yet.

## Content Security Policy

On store hosts (and the storefront host in split mode) the storefront policy is enforced with per-request
nonces (`CSP_STOREFRONT_ENFORCE=store_hosts`, default). `all` enforces on the shared host too; `off` restores
the report-only behaviour (`CSP_ENFORCE`, `CSP_ENFORCE_TEMPLATES`).
