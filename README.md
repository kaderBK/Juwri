<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/readme/logo-on-dark.png">
  <img src="docs/readme/logo-on-light.png" alt="juwri" width="360">
</picture>

<br>

### **An agentic e-commerce platform for every kind of store.**

A storefront, catalogue, ordering, POS, payments, fulfilment, inventory, loyalty and reporting in **one self-hosted application** — built for people and driven natively by software agents over **MCP**.

<br>

[![GitHub Stars](https://img.shields.io/github/stars/kaderBK/Juwri?style=for-the-badge&logo=github&labelColor=111111&color=0E9F6E)](https://github.com/kaderBK/Juwri/stargazers)
[![Licence: AGPL-3.0-only](https://img.shields.io/badge/licence-AGPL--3.0--only-0E9F6E?style=for-the-badge&labelColor=111111)](LICENSE)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&labelColor=111111&logo=php&logoColor=white)](INSTALL.md)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&labelColor=111111&logo=laravel&logoColor=white)](INSTALL.md)
[![Livewire 4](https://img.shields.io/badge/Livewire-4-FB70A9?style=for-the-badge&labelColor=111111&logo=livewire&logoColor=white)](INSTALL.md)
[![MCP Ready](https://img.shields.io/badge/MCP-5%20Endpoints-10B981?style=for-the-badge&labelColor=111111)](#agentic-commerce-and-mcp)
[![11 Store Verticals](https://img.shields.io/badge/Verticals-11-0E9F6E?style=for-the-badge&labelColor=111111)](#one-engine-many-verticals)

<br>

<table>
  <tr>
    <td align="center"><a href="#quick-start"><b>🚀 Quick Start</b></a></td>
    <td align="center"><a href="#one-engine-many-verticals"><b>🏪 11 Verticals</b></a></td>
    <td align="center"><a href="#agentic-commerce-and-mcp"><b>🤖 MCP &amp; Agents</b></a></td>
    <td align="center"><a href="#capabilities"><b>⚡ Capabilities</b></a></td>
    <td align="center"><a href="#inside-the-app"><b>🖥️ Inside the App</b></a></td>
    <td align="center"><a href="#storefronts-and-languages"><b>🎨 Storefronts</b></a></td>
    <td align="center"><a href="#editions"><b>⚖️ Editions</b></a></td>
  </tr>
</table>

<br>

<img src="docs/readme/hero.svg" width="100%" alt="juwri hero: one commerce engine lighting up nine store verticals — restaurant, cafe, retail, grocery, clothing, pharmacy, laundry, appointments and hotel">

<br>

**[Install →](INSTALL.md)** &nbsp;·&nbsp; **[Developer docs](docs/development.md)** &nbsp;·&nbsp; **[Extension points](docs/extensions.md)** &nbsp;·&nbsp; **[Docker guide](docker/README.md)** &nbsp;·&nbsp; **[Licence FAQ](LICENSE-FAQ.md)** &nbsp;·&nbsp; **[⭐ Star this repository](https://github.com/kaderBK/Juwri/stargazers)**

</div>

<br>

> [!TIP]
> **One commerce engine, zero schema forks.** Restaurants are one vertical, not the whole product: retail, grocery, clothing, cosmetics, car parts, pharmacy, laundry, appointment services and hotels run on the exact same Laravel 13 + Livewire 4 application in **11 languages** (including RTL Arabic and Urdu). Made by **JUWRI LTD** (England and Wales, company number 14363043). This repository is the **Community Edition**.

---

## One engine, many verticals

<div align="center">

<img src="docs/readme/verticals.svg" width="100%" alt="Animated map of eleven store verticals grouped into food, goods, services and stays, with the fulfilment modes listed beneath">

</div>

A store's type selects its item form, vocabulary, storefront theme set and allowed fulfilment modes — **one capability set, no schema fork**. Dine-in and kitchen tickets belong to food; shipping, variants and stock belong to goods; appointment slots and at-home visits belong to services; room nights belong to stays.

<table>
<tr>
<td width="50%" valign="top">

### 🍽️ Restaurant · `food` vertical
<img src="assets/store_templates/table/cover.png" alt="Restaurant storefront with table ordering" width="100%"><br>
<sub><b>Table ordering, menus &amp; kitchen tickets.</b> Built-in dine-in, QR tables, takeaway and delivery.</sub>

</td>
<td width="50%" valign="top">

### 🛒 Grocery · `goods` vertical
<img src="assets/store_templates/basket/cover.png" alt="Grocery storefront with a fresh produce catalogue" width="100%"><br>
<sub><b>Product catalogue, baskets &amp; delivery slots.</b> Stock tracking, expiry dates and scheduled dispatch.</sub>

</td>
</tr>
<tr>
<td width="50%" valign="top">

### 👕 Clothing retail · `goods` vertical
<img src="assets/store_templates/aisle/cover.png" alt="Clothing and fashion retail storefront" width="100%"><br>
<sub><b>Size &amp; colour variants, collections &amp; shipping.</b> Multi-option SKUs, bundles and address delivery.</sub>

</td>
<td width="50%" valign="top">

### 📅 Appointments · `services` vertical
<img src="assets/store_templates/book/cover.png" alt="Salon and appointment booking storefront" width="100%"><br>
<sub><b>Bookable services, staff &amp; time slots.</b> In-store or at-home bookings with staff scheduling.</sub>

</td>
</tr>
</table>

<details>
<summary><b>🔍 Click to explore all 11 store verticals and 9 fulfilment modes</b></summary>

<br>

| Group | Verticals | Primary Fulfilment Modes |
|---|---|---|
| **Food** | Restaurant, Café | `dine-in`, `takeaway`, `delivery` |
| **Goods** | Retail store, Grocery store, Clothing store, Cosmetics, Car parts, Pharmacy (OTC) | `ship to address`, `pickup`, `delivery` |
| **Services** | Laundry and services, Appointments and services | `appointment`, `in-store`, `at home`, `pickup` |
| **Stays** | Hotel | `room service`, stay bookings |

The authoritative configuration lives in [`core/config/verticals.php`](core/config/verticals.php).

</details>

---

## Agentic commerce and MCP

<div align="center">

<img src="docs/readme/mcp-agents.svg" width="100%" alt="Animated MCP architecture: AI clients connect through five scoped Model Context Protocol servers into the juwri commerce runtime">

</div>

juwri is **agent-ready by design**: software agents can browse catalogues, manage carts, place orders, check fulfilment and read developer docs through five built-in **Model Context Protocol (MCP)** endpoints alongside documented REST APIs and signed webhooks. Storefront themes ship **zero JavaScript** — cart, checkout and ordering stay in the platform's commerce runtime.

| Endpoint | Authentication Scope | Intended For |
|---|---|---|
| `POST /mcp` | Owner API token (or OAuth with `mcp:use`) | Store operations, catalogue edits, order management |
| `POST /mcp/catalog` | None (public, read-only) | Public product discovery, prices, availability |
| `POST /mcp/storefront` | Agent-scoped storefront token | Concierge shopping, cart building, guided checkout |
| `POST /mcp/customer` | Customer token | Order history, loyalty balance, active bookings |
| `POST /mcp/dev` | None (public, read-only) | Coding agents reading juwri architecture & extension docs |

<details>
<summary><b>🤖 Click to copy an MCP client snippet (Claude Desktop / Cursor / OpenCode)</b></summary>

<br>

```json
{
  "mcpServers": {
    "juwri-storefront": {
      "url": "https://your-store.example.com/mcp/storefront",
      "headers": {
        "Authorization": "Bearer YOUR_AGENT_SCOPED_TOKEN"
      }
    },
    "juwri-dev": {
      "url": "https://your-store.example.com/mcp/dev"
    }
  }
}
```

Full API, webhook, theme and MCP documentation: **[`docs/extensions.md`](docs/extensions.md)**. Companion automation apps for **Make**, **n8n** and **Zapier** live in [`integrations/`](integrations/).

</details>

---

## Capabilities

<div align="center">

<a href="docs/readme/features.svg"><img src="docs/readme/features.svg" width="100%" alt="Animated capability map: eight commerce capabilities connected to one engine, with an MCP agent endpoint"></a><br>
<sub>Click the diagram to open the standalone SVG and hover any capability tile.</sub>

</div>

<br>

<table>
<tr>
<td width="33%" valign="top">

### 🛍️ Commerce Core
- ✅ Catalogue, items &amp; modifiers
- ✅ Variants, bundles &amp; expiry
- ✅ Cart, checkout &amp; POS
- ✅ Payments, refunds &amp; receipts
- ✅ Fiscal &amp; e-invoicing

</td>
<td width="33%" valign="top">

### ⚙️ Operations
- ✅ Inventory &amp; multi-branch stock
- ✅ Branches &amp; table/floor maps
- ✅ Kitchen &amp; fulfilment boards
- ✅ Delivery tracking &amp; drivers
- ✅ Reservations, bookings &amp; HR

</td>
<td width="33%" valign="top">

### 📈 Growth &amp; AI
- ✅ Customers, segments &amp; loyalty
- ✅ Gift cards &amp; promo campaigns
- ✅ Marketing automation
- ✅ Analytics &amp; exports
- ✅ AI, MCP, webhooks &amp; Make/n8n/Zapier

</td>
</tr>
</table>

<details>
<summary><b>📦 Click to list all 33 modules inside core/app/Modules</b></summary>

<br>

`Accounting` · `Agents` · `Ai` · `Analytics` · `AppStore` · `Auth` · `Bookings` · `Channels` · `Content` · `Customers` · `Delivery` · `Fiscal` · `Hotel` · `Hr` · `Integrations` · `Inventory` · `Kitchen` · `Laundry` · `Loyalty` · `Marketing` · `Media` · `Menu` · `Messaging` · `Ordering` · `Payments` · `Pharmacy` · `Pos` · `Reservations` · `Storefront` · `Tables` · `Team` · `Verticals`

</details>

---

## One flow, any vertical

<div align="center">

<img src="docs/readme/order-flow.svg" width="100%" alt="Animated five-stage flow: catalogue, cart or booking, fulfilment, payment, reports">

</div>

Whether the item is a dish, a T-shirt, a room night or a haircut, it travels through the exact same pipeline:
**Catalogue → Cart or booking → Fulfilment → Payment → Reports**. No separate microservice per step, no per-vertical fork of the database schema.

---

## Inside the app

Real screens from a live demo install (food vertical shown):

<table>
<tr>
<td width="50%" valign="top">

### 📋 Orders &amp; live service
<img src="assets/docs/img/orders.png" alt="Orders queue with delivery, takeaway and table orders, statuses and receipts" width="100%"><br>
<sub>Unified live order queue across dine-in, takeaway, delivery, statuses and receipts.</sub>

</td>
<td width="50%" valign="top">

### 💳 Point of sale (POS)
<img src="assets/docs/img/pos.png" alt="Point of sale register with a menu grid, categories and an order cart" width="100%"><br>
<sub>Fast touch register for counter, table service, takeaway and delivery.</sub>

</td>
</tr>
<tr>
<td width="50%" valign="top">

### 🗂️ Catalogue &amp; modifiers
<img src="assets/docs/img/menu-items.png" alt="Item workspace with categories, translations and dietary labels" width="100%"><br>
<sub>Categories, variants, modifiers, dietary labels and translations in one workspace.</sub>

</td>
<td width="50%" valign="top">

### 📱 QR entry points
<img src="assets/docs/img/qr-codes.png" alt="QR code generator with colour, padding and corner radius settings" width="100%"><br>
<sub>Built-in branded QR generator with colour, padding and corner-radius controls.</sub>

</td>
</tr>
</table>

---

## Storefronts and languages

A storefront is a **theme**, not a separate frontend build. Themes are **Liquid packages with zero JavaScript**, validated before installation; several ship ready to use out of the box.

<table>
<tr>
<td width="33%" align="center" valign="top">
<img src="assets/store_templates/dough/preview.png" alt="Dough storefront theme, a warm pizzeria menu" width="220"><br>
<sub><b>Dough</b> · food vertical</sub>
</td>
<td width="33%" align="center" valign="top">
<img src="assets/store_templates/velvet/preview.png" alt="Velvet storefront theme, a dark fine-dining menu" width="220"><br>
<sub><b>Velvet</b> · food vertical</sub>
</td>
<td width="33%" align="center" valign="top">
<img src="assets/store_templates/orbit/preview.png" alt="Orbit storefront theme with an Arabic right-to-left diner menu" width="220"><br>
<sub><b>Orbit</b> · Arabic right-to-left</sub>
</td>
</tr>
</table>

<details>
<summary><b>🌍 Click to see all 11 supported interface languages (including RTL)</b></summary>

<br>

- **Right-to-Left (RTL):** Arabic, Urdu
- **Left-to-Right (LTR):** English, German, Spanish, French, Hindi, Portuguese, Russian, Turkish, Chinese

Translation files live in [`core/lang`](core/lang).

</details>

---

## Quick start

<div align="center">

<img src="docs/readme/quickstart-terminal.svg" width="100%" alt="Animated terminal typing the commands of a local development install and the development server starting">

</div>

### 60-second local trial (SQLite, no web server needed)

```bash
cd core
composer install
cp .env.example .env
php artisan key:generate
touch database/juwri.sqlite   # set DB_CONNECTION=sqlite and DB_DATABASE=<absolute path> in .env
php artisan migrate --seed
php artisan serve
```

<details>
<summary><b>🐳 Docker Compose &amp; Production server checklist</b></summary>

<br>

- **Docker:** follow [`docker/README.md`](docker/README.md)
- **Shared hosting, cPanel, OpenLiteSpeed &amp; VPS:** follow [`deploy/HOSTING.md`](deploy/HOSTING.md)
- **Full zero-to-production runbook:** follow [`INSTALL.md`](INSTALL.md):
  1. Check requirements &amp; install dependencies (`INSTALL.md` §1–2)
  2. Configure `.env` (`INSTALL.md` §3)
  3. Run `php artisan migrate --seed` (`INSTALL.md` §4)
  4. Run the machine check &amp; create the first admin (`INSTALL.md` §5–6)
  5. Start the queue worker &amp; scheduler (`INSTALL.md` §7)
  6. Configure storage &amp; files (`INSTALL.md` §8)
  7. Upgrades &amp; backups: [`docs/ops/upgrade-notes.md`](docs/ops/upgrade-notes.md) · [`docs/ops/backup-restore.md`](docs/ops/backup-restore.md)

</details>

---

## Editions

<div align="center">

| Capability / Scope | Community Edition (this repo) | Enterprise |
|---|:---:|:---:|
| **Licence** | `AGPL-3.0-only` + Extension Exception | juwri Enterprise Licence |
| **Own business:** 1 store per vertical, up to 3 branches/store, unlimited staff | ✅ Free | ✅ |
| **Multi-store chains** beyond 1 store/type or 3 branches | Chain licence / Cloud | ✅ Licensed count |
| **Hotel, pharmacy, laundry, accounting &amp; sales channels** | ✅ | ✅ |
| **5 built-in MCP servers &amp; Make/n8n/Zapier apps** | ✅ | ✅ |
| **Platform SaaS:** multi-tenant sign-up, billing plans, reseller, white-label, SSO, audit log | — | ✅ |

</div>

> [!NOTE]
> The Community codebase never depends on the Enterprise package. **juwri Cloud** (`juwri.com`) is a hosted service operated by JUWRI LTD; hosted-only services (AI credits, embedded payments, messaging bundles, partner connectors) belong to Cloud and not to this repository.

---

## Inside the repository

```mermaid
flowchart TD
  R["juwri Community Edition"] --> C["core/ — Laravel 13 and Livewire 4"]
  R --> A["assets/ — owner UI, storefront themes, docs images"]
  R --> I["integrations/ — Make, n8n and Zapier"]
  R --> S["storefront-starter/ — headless storefront example"]
  R --> D["docker/ and deploy/ — self-hosting"]
  R --> O["docs/ — developer and operations docs"]
```

<details>
<summary><b>📁 Expand: repository layout, file by file</b></summary>

<br>

- `core/` — the Laravel application (modules in `core/app/Modules`, translations in `core/lang`).
- `assets/` — front-end assets, the owner UI kit and storefront themes.
- `integrations/` — Make, n8n and Zapier apps.
- `storefront-starter/` — a headless storefront example.
- `docker/`, `deploy/` — self-hosting files and runbooks.
- `docs/` — developer and operations documentation.

Enterprise companion clients (`desktop/`, `kiosk/`, `mobile/`, `driver-app/` in the private tree) are not
part of the Community Edition mirror.

</details>

---

## Contributing &amp; Security

- **Contributing:** Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md). Contributors sign the juwri CLA ([`CLA-INDIVIDUAL.md`](CLA-INDIVIDUAL.md), [`CLA-CORPORATE.md`](CLA-CORPORATE.md)); you keep your copyright. Setup: [`docs/development.md`](docs/development.md) · Extension points: [`docs/extensions.md`](docs/extensions.md).
- **Security:** Do not open public issues for vulnerabilities. Report them privately via the repository **Security** tab (**Report a vulnerability**); see [`SECURITY.md`](SECURITY.md). **There is no security email address.**

## Licence

- Everything in this repository is **AGPL-3.0-only** ([`LICENSE`](LICENSE)). If you modify juwri and let others use it over a network, you must offer them your modified source.
- Themes, plugins and integrations using only documented extension points may use any licence ([`EXTENSION-EXCEPTION.md`](EXTENSION-EXCEPTION.md)).
- Commercial licensing is available (`sales@juwri.com`). Third-party notices: [`THIRD-PARTY.md`](THIRD-PARTY.md) &amp; `THIRD-PARTY-NOTICES.md`. Trademarks: [`TRADEMARKS.md`](TRADEMARKS.md). More: [`LICENSING.md`](LICENSING.md) &amp; [`LICENSE-FAQ.md`](LICENSE-FAQ.md).

---

<div align="center">

### ⭐ Star `kaderBK/Juwri` if juwri helps power your store or agent workflows!

[![Stars](https://img.shields.io/github/stars/kaderBK/Juwri?style=social)](https://github.com/kaderBK/Juwri/stargazers)

**juwri Community Edition** · Made by JUWRI LTD (England and Wales, company number 14363043)

[⭐ Star on GitHub](https://github.com/kaderBK/Juwri/stargazers) &nbsp;·&nbsp; [Read INSTALL.md](INSTALL.md) &nbsp;·&nbsp; [Read the licence FAQ](LICENSE-FAQ.md)

<br>

<sub>All motion comes from pure SMIL SVG animations — zero JavaScript and zero inline scripts.</sub>

</div>
