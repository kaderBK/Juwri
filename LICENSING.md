# Licensing

juwri is open source, made by JUWRI LTD. This page explains the licences in plain words; the
LICENSE files are what legally apply.

## 1. Community Edition: AGPL-3.0-only
Everything in this repository is licensed under the GNU Affero General Public License, version 3
only. You may use, study, change and share it, including commercially. Running your own
restaurant, café or shop on juwri is free: the Community Edition includes one store of each type, with
up to three branches per store, and unlimited staff. For more stores or branches, use a juwri Chain
licence or juwri Cloud.

If you change juwri and let others use it over a network (as a website, app or service), the AGPL
requires you to offer those users the complete source code of your changed version.

## 2. Enterprise features (distributed separately)
The Enterprise features are not in this public repository. JUWRI LTD delivers them to subscribers
as a separate package (installed into `core/ee/`) under the juwri Enterprise Licence. To evaluate
them before buying, ask for evaluation access; development and testing are free under that
licence. Production use needs a juwri Enterprise subscription, counted per account (each business
signed up on your platform). They cover
running juwri as a platform for other businesses (multi-account sign-up, selling plans to
tenants, reseller and white-label tools, marketplace commissions) and large-organisation controls
(SSO, audit log, chain tools, organisation-wide agent governance).

This repository is a complete juwri on its own, entirely AGPL. A business running itself has one store of
each type, up to three branches per store, and unlimited staff; more stores or branches need a juwri Chain
licence or juwri Cloud. The Community Edition includes the specialised modules: hotel, pharmacy,
laundry, accounting and sales-channel connectors. Community code never depends on the Enterprise
package, and an automated test enforces this. Some connectors need partner credentials that only
juwri Cloud provides (for example delivery-app and POS partner access).

## 3. Builds and images
Public builds and container images ("community") contain only this repository's AGPL code.
Enterprise builds and the Enterprise package are available only to subscribers and evaluators,
and are under both licences.

## 4. Other licences in this repository
- Third-party components keep their licences: see `THIRD-PARTY.md`.
- The n8n integration (`integrations/n8n-nodes-juwri`) is an Extension under `EXTENSION-EXCEPTION.md` and is licensed under the MIT licence, not the AGPL.

## 5. Themes, plugins and integrations
Themes, plugins and integrations that use only juwri's
published extension points and APIs do not have to be AGPL; see `EXTENSION-EXCEPTION.md`.
Changes to juwri itself are still covered by the AGPL.

## 6. juwri Cloud
juwri Cloud (juwri.com) is the hosted service run by JUWRI LTD under the juwri Subscription Terms (https://juwri.com/terms).
Some services, such as AI credits, embedded payments and messaging bundles, are part of Cloud and
not of this repository.

## 7. Commercial licence for the Community code
If you can't meet the AGPL's source-sharing terms, JUWRI LTD offers a commercial licence for the
Community code too. Contact sales@juwri.com.

## 8. Contributions
Contributors sign the juwri CLA, so JUWRI LTD can offer the code under both the AGPL and
commercial licences. Contributors keep their copyright, and JUWRI LTD promises that their
contributions will always also be available under an open-source licence.

## 9. Name and logo
None of these licences grant rights to the juwri name or logo. See `TRADEMARKS.md`.
