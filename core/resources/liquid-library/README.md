# Liquid section library (Theme Studio)

Platform-owned, versioned Liquid building blocks for Studio themes — see
`rev/theme-studio/IMPLEMENTATION-SPEC.md` (phase B).

```
liquid-library/<version>/
  sections/<type>.liquid   markup + {% schema %}
  snippets/<name>.liquid   shared {% render %} partials
  locales/<lang>.json      storefront strings for the `t` filter
```

A theme opts in with `"library": "<version>"` in its `theme.json`
(`App\Storefront\SectionLibrary`). The theme's own `sections/`, `snippets/`
and `locales/` still win on a name clash.

**Versioning rule:** once any published theme pins a version, that version's
files are frozen. Changes ship as a new version directory; themes move to it by
being re-released. Version `1` is being filled during phase B and is not pinned
by any published theme yet.
