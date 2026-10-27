---
title: "AI phone agent"
audience: owner
group: Users
updated: 2026-10-12
---

# AI phone agent

Your restaurant can have an AI phone agent that answers calls and takes orders.
Open **AI phone agent** in the dashboard sidebar (page path `phone-agent`). If
you own more than one restaurant, use the dropdown at the top of the page to
pick which restaurant you are configuring; every setting is per restaurant. The
phone agent needs the **AI phone agent** feature on your plan (a Cloud add-on).

## What it does

The agent answers each call, plays a spoken notice and your greeting, takes
pickup and dine-in style orders, and can hand the caller off to your staff. On
every call it says **"This call is handled by an AI assistant."** and then your
greeting (the default is **"Hi! How can I help with your order today?"**).

## Connect a Twilio (default) or Telnyx number

On the page you register a phone number and point it at the agent. Fields:

- **Provider** — **Twilio** (default, first in the list) or **Telnyx**.
- **Phone number** — in international format, starting with `+`.
- **Greeting** — the first thing the caller hears.
- **Staff handoff number** — where calls go when they are transferred.
- **Active hours** — for example `09:00-23:00`.
- **Monthly minute cap** — leave empty to use the plan default of 600 minutes.
- **Recording region** — Saudi Arabia, United Arab Emirates, European Union
  or Other.
- **Auth token** — bring your own key (BYOK); it is stored encrypted.
- Switches: **Take orders**, **Record calls** and **Payment by link only**.

Click **Save**. A number can be registered to one store only; reusing one gives
the error **"That number is already registered to another store."** Each line
has a **Disable** or **Enable** button and a **Settings** section with the same
fields and a **Save settings** button.

On the provider side, point the number's voice webhook at
`POST {your-site}/voice/{twilio|telnyx}/incoming`. Saving with provider API
credentials also sets the call-end callback `{site}/voice/{provider}/status` for
you. Those credentials are Twilio account SID and auth token, or Telnyx API key
and connection ID (TeXML application id). Without them the line still saves; set
the callback URL by hand. For Telnyx, save the portal's public key (**Keys and
Credentials**, **Public Key**) as your public key. Telnyx is experimental;
Twilio is the reference.

## Recording consent by region

Audio recording is **off by default**. Audio is kept only when all three of
these are true:

- the **Record calls** switch is on;
- a **Recording region** is chosen — saving with recording on and no region is
  refused with **"Recording region is required when recording is on."**;
- the caller says yes when asked. Before recording the agent says **"This call
  is handled by an AI assistant and may be recorded. Say yes if you agree to
  recording."** Silence, "no" or anything else means the call is not recorded.

The same explicit spoken yes is used in every region, following the strictest
rule (Saudi Arabia and the United Arab Emirates).
Transcripts are kept regardless of recording consent. They are PII-redacted —
personal details and card digits are masked before saving.

Retention caps, in days (transcript / audio):

| Region | Transcript | Audio |
| --- | --- | --- |
| Saudi Arabia | 30 | 7 |
| United Arab Emirates | 30 | 7 |
| European Union | 30 | 14 |
| Other | 90 | 30 |

A line with no region uses the Saudi Arabia limits; older recordings and
transcripts are purged automatically (command `voice:purge-retention`). Note:
this is not legal advice; check the law where you operate.

## Pay by link

The agent takes pickup and dine-in style orders through the same menu tools as
the chat agent. Prices come from your menu, and it confirms every order with
**"Shall I place it?"** before creating it. Phone orders are marked with source
**phone**.

The agent never takes card numbers by voice. Card-like runs of digits (English
and Arabic digits) are removed, and it tells the caller it will text a payment
link.

Phone orders are created unpaid. A secure payment link is texted (SMS) to the
caller's number. The kitchen — kitchen display (KDS), printing and announcements
— does **not** see the order until it is paid; once paid it goes to the kitchen.
If it is not paid within 30 minutes the order is cancelled automatically and any
stock is returned.

Payment by link needs an online payment gateway switched on for the store. If
there is none, or the caller ID is hidden (withheld, anonymous, private or
unknown), no order is created and the call goes to your staff. The
**Payment by link only** switch forces link payment on the line.

## Staff handoff number and hours

The agent hands a call to your staff when the caller asks for a human, on too
many misunderstandings, a tool error, ordering switched off, the minute cap, a call that
is too long or has too many turns, a hidden caller ID, or no payment gateway.

If a **Staff handoff number** is set and the call is inside **Active hours**,
the call is transferred; otherwise the agent takes a callback request and your
store is notified. **Active hours** is free text, for example `09:00-23:00`,
`9 AM - 11 PM` or `24/7`; an empty value always counts as inside hours.

## Call language

The agent detects the caller's language and answers in it (Arabic or English).

## Minutes and the plan

The phone agent add-on is **$149 per location per month**, including 600 minutes
with no per-order fee; in SAR it is **SAR 559**. Overage is **$0.20 per minute**
(config default).

Per-call limits are 8 minutes, 20 caller turns and 3 simultaneous calls per
line. The **Monthly minute cap** is per store (the lowest among your lines),
otherwise 600 minutes. At the cap, new calls go to handoff.

## Call log

The **Recent calls** list shows recent calls, with **View all calls** for the
full list. Each call shows the caller, outcome, duration, linked order and
transcript.

## Data deletion and retention

Call logs and recorded audio belong to the store; they are deleted with the
store and move with it on transfer, and the retention purge deletes old
recordings and transcripts automatically.
