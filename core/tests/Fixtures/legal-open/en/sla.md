# juwri Service Level Agreement

Version 1.0 · Effective 4 October 2026

This Service Level Agreement ("**SLA**") forms part of the juwri Subscription Terms ("**Terms**")
between **JUWRI LTD** (company number 14363043, England and Wales) ("**juwri**", "**we**", "**us**")
and you ("**Customer**", "**you**"), as referred to in Terms 2.8. Capitalised words not defined here
have the meaning in the Terms. If this SLA and the Terms conflict, the Terms prevail.

Everything in this SLA is subject to your plan. If your Order states different service levels, the
Order prevails.

## 1. Definitions

- **Core Services**: the parts of the Cloud Services that let your business take and fulfil orders,
  namely: the public storefront and QR menu for your stores; the checkout and order-placement API;
  the point-of-sale and kitchen-screen order sync API; and your owner dashboard login.
- **Excluded Event**: any event listed in section 3.
- **Higher plans**: {{FILL:higher_plans}}.
- **Standard plans**: paid plans that are not higher plans.

- **Service Credit**: a credit against future fees. None are offered at launch (section 4).
- **Support Hours**: the hours in section 7.
- **Business Day**: a day that is not a weekend day or public holiday in the region of the support
  team that handles your request (see section 7.3).

## 2. Scope: who is covered

2.1 **Paid Cloud plans only.** This SLA covers support and availability information for the paid
juwri Cloud plans, and support for paid Enterprise subscriptions (section 8). It does not cover any
add-on unless the Order says so.

2.2 **Not covered.** This SLA does not cover:
- free plans, trials, and features marked "beta", "preview" or similar (Terms 2.7);
- the Partner Relay, unless your Order says otherwise (Terms 4.2);
- third-party services, including payment gateways, delivery platforms, point-of-sale systems,
  messaging providers (SMS, WhatsApp, email), map, AI model providers and accounting software (Terms
  4.1);
- the AGPL Software and the Enterprise Software running on your own infrastructure, which are
  covered by the support terms in section 8 only;
- custom domains, DNS and hosting that you or a third party manage;
- AI features, messaging deliverability and the content of any message or page;
- sandbox, staging, demo and test accounts.

2.3 **Nothing here is a promise of perfect operation.** The targets in this SLA are commitments
about support response. They are not a promise that the Services will be uninterrupted or error-free
(Terms 11.2).

## 3. Exclusions

These events ("**Excluded Events**") are outside the support and incident targets in this SLA, and
outside any uptime commitment introduced later:

3.1 **Scheduled maintenance.** Maintenance that we announce at least 48 hours in advance by email,
in the dashboard or on the status page https://status.juwri.com, and that takes place in a
maintenance window. Maintenance windows are 01:00 to 04:00 Europe/London time (04:00 to 07:00 Riyadh
time during UK winter time), chosen as the lowest-traffic period across our UK and Gulf customers.
We aim to keep scheduled maintenance below 4 hours a month.

3.2 **Emergency maintenance.** Urgent maintenance to protect the security or integrity of the
Services or customers' data, such as applying a critical security patch, where advance notice is not
possible. We will notify on the status page as soon as practicable.

3.3 **Your actions.** Problems caused by you, your Users or your systems: incorrect configuration,
network or device faults, expired payment or domain details, use that breaks the Terms or the
Acceptable Use Policy at https://juwri.com/legal/aup, or exceeding plan limits or rate limits.

3.4 **Third parties.** Faults in third-party services or networks outside our reasonable control,
including your internet provider, payment gateway, delivery platform or messaging provider, and
failures of the wider internet.

3.5 **Suspension.** Suspension or limits applied under Terms 6.4 or 7.4.

3.6 **Force majeure.** Events beyond our reasonable control (Terms 14.4), including natural
disaster, war, terrorism, government action, power or telecommunications failure and widespread
attacks such as a large denial-of-service attack, where we applied reasonable protections.

3.7 **Beta, free and unsupported use.** Use of features or versions excluded by section 2.2, or use
of software versions we no longer support.

3.8 **Features in testing.** New features switched on at your request before general release.

## 4. Availability at launch

4.1 **No uptime commitment yet.** At launch, juwri does not offer a guaranteed uptime percentage or
Service Credits for any plan. We design and run the Core Services to be highly available, and we
will work to restore them as quickly as we can when something goes wrong, but availability is not a
contractual commitment under this SLA.

4.2 **Status page.** We publish incidents, scheduled maintenance and their resolution at
https://status.juwri.com, and you can subscribe to updates there.

4.3 **Incident reports.** After a Severity 1 incident affecting many customers, we will publish a
short summary of what happened and what we changed, normally within 5 Business Days.

4.4 **Future commitment.** We intend to introduce an uptime commitment with Service Credits for paid
plans. When we do, we will publish it here and it will apply from the date stated, without any
action from you. Introducing or improving a commitment is not a change that makes the service levels
worse under section 10.2.

4.5 **Your other rights.** This section does not limit your rights under the Terms, including
termination for material breach (Terms 7.3) and the remedy for material failure to match the
documentation (Terms 11.1).

## 5. Uptime commitment

Reserved. No uptime commitment is offered at launch (section 4).

## 6. Service Credits

Reserved. No Service Credits are offered at launch (section 4).

## 7. Support

7.1 **Channels.** You can contact support by:
- email: support@juwri.com;
- the in-dashboard help widget;
- WhatsApp business line: {{FILL:whatsapp_number}};
- phone, for higher plans only: {{FILL:support_phone}}.

For a suspected security incident, email security@juwri.com (https://juwri.com/security). For abuse,
email abuse@juwri.com.

7.2 **Languages and hours.** Support is in English and Arabic, during these Support Hours:
- Sunday to Thursday, 09:00 to 18:00 Riyadh time (Asia/Riyadh); and
- Monday to Friday, 09:00 to 17:30 UK time (Europe/London).

Outside those hours, requests are logged and answered when the next period starts, except for
Severity 1 incidents (see 7.5).

7.3 **Business Days.** The weekend is Friday and Saturday in Saudi Arabia, Kuwait and Egypt, and
Saturday and Sunday in the United Kingdom. You can contact us in either period, whatever your
country. Public holidays of that region are not Business Days. We publish the holiday calendar at
{{FILL:holiday_calendar_url}}.

7.4 **Priority support.** On higher plans, Severity 1 incidents ("ordering is down") are handled 24
hours a day, every day, with phone and WhatsApp priority and the shorter targets in 7.5.

7.5 **Severity levels and response targets.** When you contact us we set the severity with you using
the table below. "Response" means a first reply from a person qualified to work on the issue. It is
not a promise to fix within that time.

| Severity | Definition | Standard plans | Higher plans |
|---|---|---|---|
| Severity 1 (critical) | Ordering is down: customers cannot place orders on storefronts, or the point-of-sale or kitchen screens cannot sync orders, for all or most of your stores, and there is no workaround | 4 working hours, in Support Hours | 1 hour, 24 hours a day, every day |
| Severity 2 (high) | A major feature is severely degraded or a store is partly down, and a workaround is difficult | 8 working hours | 4 hours, in Support Hours |
| Severity 3 (normal) | A feature is not working as documented, with a workaround | 2 Business Days | 1 Business Day |
| Severity 4 (low) | A question, how-to, feature request or cosmetic issue | 3 Business Days | 2 Business Days |

7.6 **Updates.** For Severity 1 we will update you at least every 2 hours until it is resolved or
mitigated, and publish it on https://status.juwri.com. For Severity 2 we will update at least once a
Business Day.

7.7 **Your duties.** To receive support you must give the details we reasonably ask for, a named
contact who can work with us, and access to your Account where needed, and tell us promptly of any
change.

7.8 **What support covers.** Support covers using and troubleshooting the Services. It does not
cover training on non-juwri software, custom development, your own website code, or third-party
services. Training and consultancy can be bought separately.

7.9 **POS offline mode.** The juwri point-of-sale keeps taking orders when the device is offline.
The device stores them and syncs when the connection returns. Taking orders offline keeps your
business running, but features that need the network (for example online payments, live
kitchen-screen updates, coupon checks, loyalty look-ups and delivery-platform orders) may not work
until the device is online. You should test offline mode and keep a manual fallback for your
business.

## 8. Self-hosted Enterprise support

8.1 **Who.** This section applies to customers with a paid Enterprise subscription for the
Enterprise Software on their own infrastructure (Terms 2.2).

8.2 **Response targets.** The Severity levels and response targets in 7.5 apply, with these changes:
- we respond within Support Hours only, and Severity 1 means the Enterprise Software, correctly
  installed on a supported version and environment, is unusable in production;
- time starts when we receive a complete report with logs and version details;
- we may ask you to reproduce the issue on a supported version.

8.3 **Supported versions.** We support the latest minor release of the Enterprise Software and the
one before it. Those versions receive security fixes (https://juwri.com/security) and bug fixes. If
you run an older version, we may ask you to upgrade before we help, and we may offer paid help to
upgrade. Critical security fixes for self-hosted releases follow the Cloud fix on the same day where
possible.

8.4 **What we do not cover.** Your infrastructure, servers, databases, backups, networks, TLS
certificates, custom code, themes and extensions, third-party packages, and unsupported versions or
environments. You are responsible for backups (and for keeping `APP_KEY` safe, as stated on the
security page), updates, uptime and capacity. No uptime commitment or Service Credit applies to
self-hosted installations.

8.5 **Remote access.** We help remotely only with your permission and through access you control.
You are responsible for the data you share with us. We will treat it under Terms 10 and, where
personal data is involved, the Data Processing Addendum.

8.6 **AGPL Software.** Support for the AGPL Software without a subscription is community support
only, with no service levels.

## 9. Backups, recovery and RPO / RTO

9.1 **Backups.** For the Cloud Services we take encrypted backups daily and keep them for 30 days
{{VERIFY:backups}}, and we test restoring them
regularly (https://juwri.com/security). Backups are held in the same region as production.

9.2 **Recovery objectives.** If a failure or disaster destroys or corrupts production data, we aim
to:
- restore the Services within 8 hours (Recovery Time Objective, "**RTO**"); and
- restore data to a point no more than 24 hours before the failure (Recovery Point Objective,
  "**RPO**").

9.3 **Targets, not guarantees.** RPO and RTO are objectives and not guarantees. Failure to meet them
does not by itself give a Service Credit or other remedy; your remedies are in Terms 11.1 and 12.

9.4 **Your own data.** You can export your Customer Data at any time using the tools in the
Services. We recommend regular exports of data that is critical to your business, such as your menu
and customer lists.

## 10. Reporting and review

10.1 **Reports.** On request, we will give you a summary of Severity 1 and 2 incidents that affected
your stores in the last 12 months.

10.2 **Changes.** We may update this SLA. We will give at least 30 days' notice of changes that make
the service levels materially worse, by email or in the Services, and changes apply from your next
renewal (Terms 14.2). We will not reduce the support targets for your plan during your current
Subscription Term.

10.3 **Resellers.** If you buy through a reseller, first-line support may be handled by the reseller
(Terms 14.1). If Service Credits are introduced later, they will be claimed through the reseller.

*Last updated: 4 October 2026 · Version 1.0*
