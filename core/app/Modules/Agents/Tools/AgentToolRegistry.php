<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Tools;

use App\Services\FeatureAccess;
use InvalidArgumentException;
use Juwri\Core\Models\User;

/**
 * P1-16: the ONE agent tool registry. Every tool (menu, availability, cart, order, history, booking, the owner's
 * catalog/order/coupon tools, ...) is defined once here and every channel reads it: the chat loop (web chat,
 * WhatsApp, SMS, voice) through AgentChatService, the owner and storefront MCP servers through their laravel/mcp
 * servers. Definitions live in {@see OwnerTools} and {@see StorefrontTools}; a channel adapter only maps I/O.
 *
 * Gating is here too, so every channel answers alike: a tool is listed/callable only when the caller's credential
 * carries its ability and the account holds its feature (FIX-6 F6-1: the serve-time twin of `feature:<key>`).
 */
final class AgentToolRegistry
{
    /** @var array<string, array<string, AgentTool>> audience => name => tool, in definition order */
    private array $tools = [];

    public static function withDefaults(): self
    {
        $registry = new self;
        OwnerTools::define($registry);
        StorefrontTools::define($registry);

        return $registry;
    }

    public function register(AgentTool $tool): void
    {
        if (isset($this->tools[$tool->audience][$tool->name])) {
            throw new InvalidArgumentException("Agent tool [{$tool->audience}:{$tool->name}] is already registered.");
        }
        $this->tools[$tool->audience][$tool->name] = $tool;
    }

    /**
     * Every tool of an audience, optionally only those offered on a channel (ungated: the full map, e.g. for a
     * landing-page count or a sweep test).
     *
     * @return array<string, AgentTool>
     */
    public function tools(string $audience, ?string $channel = null): array
    {
        $tools = $this->tools[$audience] ?? [];

        return $channel === null ? $tools : array_filter($tools, fn (AgentTool $t) => $t->offeredOn($channel));
    }

    public function find(string $audience, string $name, string $channel): ?AgentTool
    {
        $tool = $this->tools[$audience][$name] ?? null;

        return $tool !== null && $tool->offeredOn($channel) ? $tool : null;
    }

    /**
     * The tools this caller may see: offered on its channel, ability carried, feature held.
     *
     * @return array<string, AgentTool>
     */
    public function available(string $audience, ToolContext $context): array
    {
        return array_filter(
            $this->tools($audience, $context->channel),
            fn (AgentTool $t) => $context->can($t->ability) && $this->featureOk($context, $t->feature),
        );
    }

    /**
     * Run a tool for this caller. Refusals are ToolErrors (unknown tool, ability missing, not entitled) raised
     * BEFORE the handler runs — a partial result is the leak.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws ToolError
     */
    public function call(string $audience, string $name, array $args, ToolContext $context): mixed
    {
        $tool = $this->find($audience, $name, $context->channel);
        if ($tool === null) {
            throw new ToolError(ToolError::INVALID_PARAMS, "Unknown tool: {$name}");
        }
        if (! $context->can($tool->ability)) {
            throw new ToolError(ToolError::ABILITY_MISSING, "This tool requires a token with the '{$tool->ability}' ability.");
        }
        if (! $this->featureOk($context, $tool->feature)) {
            throw new ToolError(
                ToolError::NOT_ENTITLED,
                "This tool requires the '{$tool->feature}' feature, which is not available on this account."
            );
        }

        return $tool->run($context, $args);
    }

    /**
     * A null feature is always allowed. Otherwise the account must hold it: the owner for owner tools, the
     * store's owner for storefront tools (kill-switch veto always, entitlement only when plan-gated).
     */
    private function featureOk(ToolContext $context, ?string $feature): bool
    {
        if ($feature === null) {
            return true;
        }
        $ownerId = $context->userId ?? ($context->store !== null ? (int) $context->store->user_id : null);
        $owner = $ownerId ? User::find($ownerId) : null;

        return FeatureAccess::allows($owner, $feature);
    }
}
