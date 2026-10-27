---
title: "Msegat sender-name registration (Saudi Arabia)"
audience: owner
group: Integrations
updated: 2026-07-12
---

# Msegat sender-name registration (Saudi Arabia)

Before you can send SMS through Msegat, you must register a **sender name** with the
Saudi telecom regulator (CST / the Jaber system). This is a business step Msegat cannot
do for you, and it is separate from creating your Msegat account and API key.

## What you need

1. **A Msegat account** at [msegat.com](https://www.msegat.com/) with purchased points
   (balance). SMS is prepaid.
2. **A valid Commercial Registration (CR)** for the entity that will send the messages.
3. **A stamped Arabic authorization letter** on company letterhead authorizing the sender
   name, addressed as the Msegat dashboard instructs.

## Rules for the sender name

- The sender name is what recipients see as the "from". It must match your brand / CR.
- **Promotional** sender names must end with **`-AD`** (regulator requirement for marketing
  traffic). Transactional (OTP, order updates) names do not.
- Registration review typically takes **~2 weeks**. Plan ahead.

## Steps

1. Log in to the Msegat dashboard.
2. Go to the sender-name (اسم المرسل) registration section.
3. Submit the requested sender name, your CR, and the stamped authorization letter.
4. Wait for CST/Jaber approval. You will be notified in the dashboard.
5. Once approved, generate an **API key** (Settings → API).
6. In your restaurant dashboard, open **Settings → Integrations → Msegat SMS** and paste
   your **Username**, **API key**, and the approved **Sender name**, then **Save** and
   **Test connection**.

## Encoding & billing

- Arabic messages are sent as **UTF-8** and are billed at **70 characters per segment**
  (versus 160 for plain English). Keep messages concise to control cost.

## Notes

- Delivery reports from Msegat are **poll-only** (no push webhook); the platform records
  send acceptance in the SMS log.
- Your API key is stored **encrypted** and never shown back in full.
