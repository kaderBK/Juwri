# Regenerating the action-node property skeleton from OpenAPI

The action node (`nodes/Juwri/Juwri.node.ts`) exposes a hand-curated
subset of the owner REST API. Its resource/operation/routing skeleton is derived
from the platform's published OpenAPI document, then **committed as TypeScript**
so the shipped package has ZERO runtime dependencies (no OpenAPI parser or
`@devlikeapro/n8n-openapi-node` at install time — verification rule).

## Source of truth

- Machine-readable spec: `GET {baseUrl}/api/v1/openapi.json` (public, generated
  from the live router — see F0-6).
- Human reference: `{baseUrl}/developers`.

## Operations wrapped (verified against the router)

| Resource  | Operation         | Method | Path                                                  | Scope            |
|-----------|-------------------|--------|-------------------------------------------------------|------------------|
| Order     | List              | GET    | `/stores/{id}/orders`                            | `orders:read`    |
| Order     | Get               | GET    | `/stores/{id}/orders/{orderId}`                  | `orders:read`    |
| Order     | Update Status     | PUT    | `/stores/{id}/orders/{orderId}/status`           | `orders:write`   |
| Menu Item | List              | GET    | `/stores/{id}/menu`                              | `menu:read`      |
| Menu Item | Create            | POST   | `/stores/{id}/menu/items`                        | `menu:write`     |
| Menu Item | Update            | PUT    | `/stores/{id}/menu/items/{itemId}`               | `menu:write`     |
| Menu Item | Set Availability  | PATCH  | `/stores/{id}/menu/items/{itemId}/sold-out`      | `menu:write`     |
| Customer  | List              | GET    | `/stores/{id}/customers`                         | `customers:read` + `feature:crm_suite` |
| Customer  | Find              | GET    | `/stores/{id}/customers?search=`                | `customers:read` + `feature:crm_suite` |

Body fields for Menu Item Create/Update mirror `MenuAdminService::ITEM_RULES`
(`name`, `price`, `category_id`, `description`, `image`, `type`, `active`,
`is_sold_out`, `is_popular`, `is_vegan`, `is_halal`, `is_gluten_free`,
`calories`, `prep_minutes`, `position`). The Update Status body is
`{ status }` over `new|preparing|ready|delivered|completed|cancelled`.

## To regenerate after the API changes

1. Fetch the fresh spec: `curl {baseUrl}/api/v1/openapi.json > openapi.json`.
2. In a scratch project (NOT this package), run an OpenAPI→n8n generator such as
   `@devlikeapro/n8n-openapi-node` to emit the `INodeProperties[]` skeleton.
3. Copy the generated resource/operation/routing blocks into
   `Juwri.node.ts`, keeping the credential + `requestDefaults` here.
4. `npm run build && npm test` — the action-node test pins method/URL/body for
   every operation, so a drifted path fails fast.

The generator never becomes a dependency of this package; it runs once, offline,
during a maintenance pass.
