{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  I5-4 — a keyboard focus ring for the ~33 themes that ship none.

  Rendered once for every theme from the shared layout, keyed off the accent
  token so it matches the store's colour. `:focus-visible` only fires for
  keyboard / assistive-tech navigation, never mouse clicks, so it adds no visual
  noise; themes that already style focus keep theirs (their rule loads later or
  is more specific). Kept out of theme-tokens.blade.php so that partial stays
  silent for an uncustomized store (pixel-parity contract).
--}}
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>:focus-visible{outline:2px solid var(--tc-primary,#3fd463);outline-offset:2px}</style>
