{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  I5-4 — a keyboard focus ring for the ~33 themes that ship none.

  Rendered once for every theme from the shared layout. `:focus-visible` only
  fires for keyboard / assistive-tech navigation, never mouse clicks, so it
  adds no visual noise; themes that already style focus keep theirs (their
  rule loads later or is more specific). Kept out of theme-tokens.blade.php
  so that partial stays silent for an uncustomized store (pixel-parity
  contract).

  The ring is drawn on the PAGE GROUND, two pixels outside the control, so it
  is painted in the INK token — the one colour that is guaranteed to contrast
  with the ground the ring sits on. The brand colour was the wrong choice
  here: a control that carries its own background (a filled button, a chip on
  a band) would put the ring in a colour chosen for that control, on a
  background it was never measured against. The ink token follows the theme
  into its dark mode on its own.
--}}
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>:focus-visible{outline:2px solid var(--tc-text,#111);outline-offset:2px}</style>
