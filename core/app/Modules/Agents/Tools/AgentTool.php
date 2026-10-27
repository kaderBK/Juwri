<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Tools;

use Closure;
use Juwri\Core\Models\Store;

/**
 * P1-16: one agent tool, defined once and served by every channel that lists it (web chat, WhatsApp, SMS, voice,
 * MCP). Channel adapters only map I/O: the chat loop reads {@see $inputSchema} as `parameters`, an MCP server lists
 * it as `inputSchema` with {@see $annotations}. The handler receives the trusted {@see ToolContext} (store, owner,
 * verified sender) — never a client-supplied tenant.
 */
final class AgentTool
{
    /** The store owner's tools (owner MCP): scoped to the authenticated account's stores. */
    public const OWNER = 'owner';

    /** The storefront's customer-facing tools (chat channels + storefront MCP): scoped to one store. */
    public const STOREFRONT = 'storefront';

    public const CHANNEL_CHAT = 'chat';

    public const CHANNEL_MCP = 'mcp';

    /** P6-09 V-07: phone calls. Its manifest has no wallet / payment tool (see StorefrontTools::VOICE_EXCLUDED). */
    public const CHANNEL_VOICE = 'voice';

    /**
     * @param  array<string, mixed>  $inputSchema  JSON Schema of the arguments (an object schema)
     * @param  list<string>  $channels  CHANNEL_* values the tool is offered on
     * @param  Closure(ToolContext, array<string, mixed>): mixed  $handler
     * @param  string|null  $ability  token ability the caller needs (null = any authenticated caller)
     * @param  string|null  $feature  feature key the account must hold (FeatureAccess), null = ungated
     * @param  array<string, bool>  $annotations  MCP tool annotations
     * @param  (Closure(array<string, mixed>, Store): array<string, mixed>)|null  $schemaForStore  per-store schema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $audience,
        public readonly array $channels,
        public readonly Closure $handler,
        public readonly ?string $ability = null,
        public readonly ?string $feature = null,
        public readonly array $annotations = ['readOnlyHint' => true],
        public readonly ?Closure $schemaForStore = null,
    ) {}

    public function offeredOn(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /**
     * The argument schema as advertised for a store (e.g. create_order's `type` enum lists that store's own
     * fulfillment modes); the generic schema when no store is known (landing-page enumeration).
     *
     * @return array<string, mixed>
     */
    public function schemaFor(?Store $store): array
    {
        return $store !== null && $this->schemaForStore !== null
            ? ($this->schemaForStore)($this->inputSchema, $store)
            : $this->inputSchema;
    }

    /** @param  array<string, mixed>  $args */
    public function run(ToolContext $context, array $args): mixed
    {
        return ($this->handler)($context, $args);
    }
}
