<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

/**
 * P6-122b: the public Studio share page (/studio/s/{token}). It embeds the shared menu design in a sandboxed
 * `<iframe srcdoc>`, and a srcdoc document inherits this page's policy; the design is self-contained by contract
 * (inline <style>, data: images, no script), so it needs style-src 'unsafe-inline'. In exchange the page runs NO
 * script at all: script-src 'none'. Enforced, used through AddCspHeaders with this class as the argument.
 */
class StudioSharePolicy extends Policy
{
    public function configure(): void
    {
        $this
            ->addDirective(Directive::DEFAULT, Keyword::NONE)
            ->addDirective(Directive::BASE, Keyword::NONE)
            ->addDirective(Directive::SCRIPT, Keyword::NONE)
            ->addDirective(Directive::OBJECT, Keyword::NONE)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)
            ->addDirective(Directive::FRAME_ANCESTORS, Keyword::SELF)
            ->addDirective(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE, Scheme::HTTPS])
            ->addDirective(Directive::IMG, [Keyword::SELF, Scheme::DATA, Scheme::HTTPS])
            ->addDirective(Directive::FONT, [Keyword::SELF, Scheme::DATA, Scheme::HTTPS]);
    }
}
