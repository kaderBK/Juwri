{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Owner dashboard PWA offline page (PWA-SPEC §3.7). Pre-cached by the service
     worker and shown with no network, so it cannot load the kit's stylesheets:
     the few owner UI tokens it needs (assets/owner-ui/tokens.css, light + dark)
     are copied here. Theme follows the dashboard's `theme` cookie, else the OS.
     Self-contained: no Bootstrap, icon font or external stylesheet. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ function_exists('current_language') && current_language() ? current_language()->direction : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ \Juwri\Core\Support\OwnerPwa::BACKGROUND }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121215" media="(prefers-color-scheme: dark)">
<meta name="color-scheme" content="light dark">
<title>{{ ___('You are offline') }} — {{ $brand }}</title>
<style nonce="{{ csp_nonce() }}">
  :root {
    --ui-accent: {{ $color }};
    --ui-bg: #F4F2ED; --ui-surface: #FFFFFF; --ui-line: #ECE9E2;
    --ui-ink: #17171C; --ui-ink-2: #5B5B66; --ui-on-accent: #FFFFFF;
    --ui-accent-soft: color-mix(in srgb, var(--ui-accent) 10%, transparent);
    --ui-accent-text: var(--ui-accent);
    --ui-accent-ring: color-mix(in srgb, var(--ui-accent) 22%, transparent);
    --ui-r-card: 18px; --ui-r-pill: 999px;
    --ui-font: "Instrument Sans", "IBM Plex Sans Arabic", system-ui, -apple-system, "Segoe UI", sans-serif;
    color-scheme: light;
  }
  :root[data-theme="dark"] {
    --ui-bg: #121215; --ui-surface: #1B1B20; --ui-line: #2A2A31;
    --ui-ink: #F4F2ED; --ui-ink-2: #A9A9B6;
    --ui-accent-soft: color-mix(in srgb, var(--ui-accent) 20%, transparent);
    --ui-accent-text: color-mix(in srgb, var(--ui-accent) 45%, #FFFFFF);
    --ui-accent-ring: color-mix(in srgb, var(--ui-accent) 35%, transparent);
    color-scheme: dark;
  }
  * { box-sizing: border-box; }
  html, body { background: var(--ui-bg); }
  body { margin: 0; min-height: 100vh; min-height: 100dvh; display: grid; place-items: center;
         padding: max(24px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
         font-family: var(--ui-font); color: var(--ui-ink); -webkit-font-smoothing: antialiased; }
  .ui-offline { inline-size: 100%; max-inline-size: 400px; padding: 32px 24px; text-align: center;
          background: var(--ui-surface); border: 1px solid var(--ui-line); border-radius: var(--ui-r-card); }
  .ui-offline__icon { inline-size: 56px; block-size: 56px; margin: 0 auto 18px; border-radius: 50%; display: grid; place-items: center;
          background: var(--ui-accent-soft); color: var(--ui-accent-text); }
  h1 { font-size: 20px; line-height: 1.3; margin: 0 0 8px; font-weight: 700; }
  p { margin: 0 0 24px; color: var(--ui-ink-2); line-height: 1.5; font-size: 15px; }
  button { font: inherit; font-weight: 600; font-size: 15px; min-block-size: 48px; inline-size: 100%;
           border: 0; border-radius: var(--ui-r-pill); padding: 0 28px; cursor: pointer;
           background: var(--ui-accent); color: var(--ui-on-accent); }
  button:focus-visible { outline: 3px solid var(--ui-accent-ring); outline-offset: 2px; }
  .ui-offline__brand { margin: 20px 0 0; font-size: 13px; color: var(--ui-ink-2); }
</style>
<script nonce="{{ csp_nonce() }}">
  (function () {
    try {
      var m = document.cookie.match(/(?:^|; )theme=([^;]+)/);
      var t = m ? decodeURIComponent(m[1]) : null;
      if (t !== 'dark' && t !== 'light') { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
      document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
  })();
</script>
</head>
<body>
  <main class="ui-offline">
    <div class="ui-offline__icon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M2.5 8.5a14 14 0 0 1 19 0M5.5 12a9.5 9.5 0 0 1 13 0M8.8 15.4a5 5 0 0 1 6.4 0"/><path d="M12 19.2v.1"/><path d="M3.5 3.5l17 17"/></svg>
    </div>
    <h1>{{ ___('You are offline') }}</h1>
    <p>{{ ___('Check your internet connection. Your dashboard will open again as soon as you are back online.') }}</p>
    <button type="button" id="retry">{{ ___('Try again') }}</button>
    <p class="ui-offline__brand">{{ $brand }}</p>
  </main>
  <script nonce="{{ csp_nonce() }}">
    document.getElementById('retry').addEventListener('click', function () { location.reload(); });
    window.addEventListener('online', function () { location.reload(); });
  </script>
</body>
</html>
