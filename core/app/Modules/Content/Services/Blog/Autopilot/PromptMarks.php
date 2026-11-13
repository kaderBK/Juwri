<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Blog\Autopilot;

/**
 * Blog Autopilot A1: stable marker tokens embedded in each engine prompt's
 * SYSTEM message. They give the in-test fake (FakeAutopilotAi) a deterministic
 * way to route canned responses per pipeline step regardless of call order,
 * and make ai_usage/prompt logs greppable per step. Harmless to real models.
 */
final class PromptMarks
{
    public const TOPICS = '[task:blog-autopilot-topics]';

    public const OUTLINE = '[task:blog-autopilot-outline]';

    public const SECTION = '[task:blog-autopilot-section]';

    public const CLEANUP = '[task:blog-autopilot-cleanup]';

    public const TRANSLATE = '[task:blog-autopilot-translate]';
}
