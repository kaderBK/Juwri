# Offline POS: operations notes

For support and platform staff. The owner-facing page is the help-center page "Selling when the internet is down".

## Limits that apply (platform maxima, store may set lower)

Server settings: `config/pos.php` (`POS_OFFLINE_*` in `.env`). APP_KEY rotation: the per-till PIN secrets are encrypted with
APP_KEY; on Enterprise/Cloud, run `php artisan hosted:reencrypt` after a rotation (it covers them with the hosted-services secrets);
on Community there is no such command, so every till must register again after a rotation.

| Setting | Default | Platform maximum |
|---|---|---|
| Offline hours (no server contact) | 12 | 24 |
| Unsynced record retention | 7 days | 7 days |
| Offline card, one payment | SAR 250 | SAR 250 |
| Offline card, unforwarded total per till | SAR 2,500 | SAR 2,500 |

Offline card payments stay **off for every store** until the payment provider has confirmed deferred authorisation for it.
Browser tills never take offline cards (cash and manual only).

## Revoking a lost or stolen till

1. Identify the till (store, branch, device name and last contact time in the device list).
2. Revoke it (owner only: `POST /pos/offline/devices/{id}/revoke`, or the device list). The device is marked revoked; its
   token is refused everywhere except the status, sync (replay) and wipe-report calls, and it can never register again.
3. On its next contact the till first uploads the records it still holds (replay is idempotent, so nothing is lost or doubled),
   then wipes the catalogue snapshot, the unsynced queue (only once it is empty), the staff PIN data and its local key, and
   reports the wipe; from then on its token is dead.
4. A till cannot learn it was revoked without contact. Every contact renews a 3-day lease: a till that has not reached the
   server for 3 days — revoked or not — wipes its catalogue snapshot and staff PIN data on its next start and refuses new
   sales, but **keeps its encrypted unsynced queue** (records are never deleted silently). When it next reaches the server it
   uploads the queue, and if it was revoked it then finishes the wipe as in step 3.
5. Afterwards check the shift report for the till: sales that arrived after revocation are accepted (they were made while the
   till was valid) and appear in **Offline exceptions**; change any staff PIN that was on that till.
6. A new till is registered as a new device. A revoked device is never re-activated.

What is on a till: one store and branch catalogue, staff names, roles and PIN verifiers (keyed with a secret unique to that
till, so they are useless on any other device; the server's PIN hash never leaves the server), unsynced sales and payments, optional
customer name and phone typed on a sale. Never: card numbers, customer database, other stores, API tokens beyond the device
session. All of it is encrypted at rest (hardware-backed key in the app, an encrypted browser store for browser tills).

## Remote wipe (without revoking the till for good)

Use remote wipe to clear a till that is staying in service (for example a staff member left with the tablet for a day):

1. Trigger the wipe from the device list (owner only: `POST /pos/offline/devices/{id}/wipe`). The till is told on its next
   contact.
2. The till uploads its queue first, wipes everything once the queue is empty, reports the wipe (its token stops working), and
   needs a manager to sign in again online: it then registers again with a new token and a new PIN secret and downloads a new
   snapshot.
3. The request is kept on the device row: when it was asked (`wipe_requested_at`), by which owner account
   (`wipe_requested_by_user_id`), and when the till reported the wipe (`wiped_at`).

Unsynced records are never silently deleted: after 7 days the till blocks new sales and alerts a manager instead, and the
3-day lease rule above keeps the queue too. A wipe the server asked for (revoke or remote wipe) is the only action that removes
unsynced data, and only after the till has uploaded all of it.

## What a browser till keeps on disk

- Offline sales wait in an encrypted outbox in the browser (IndexedDB `pos-till-<restaurant>`), AES-GCM per row.
- The data key is wrapped twice: by a per-till escrow key the server holds (encrypted, in `pos_offline_devices.escrow`,
  covered by `hosted:reencrypt` on Enterprise/Cloud), and by each staff PIN that signed in on that till. Offline, a PIN opens it; online,
  the escrow does. Nothing is readable at rest without one of them.
- Rows move queued → sending → done (deleted) or failed (kept, retried). A failed or unsent row is never dropped.
- Tills from before this change had a plain `pos-queue`; on first start it is copied into the encrypted outbox row by
  row and only then deleted.
- Lease over: PIN wraps go, the outbox stays (readable again once online). Revoke or remote wipe: everything goes, but
  only after the outbox has uploaded.
- A till restarted offline starts on the lock screen: a staff PIN that signed in on this till before opens the store.
  Until then it refuses every sale (no sale is ever written unencrypted; the old `pos-queue` is only read, to migrate).
  Back online, the escrow opens it by itself and the outbox uploads.
- The first online visit is enough to work offline: the register, its menu and its scripts are cached for that store
  right away (no second visit needed).
- Browsers may evict site storage when the disk is low. Browser tills therefore take cash only offline and show a
  warning when persistent storage is refused; stores that need more should use the juwri app.

## Support checklist for "my till says connect to continue"

- Offline hours used up (12 h default): connect the till; it syncs and downloads a fresh snapshot.
- No server contact for 3 days: the till wiped its catalogue and PIN data but kept its unsynced sales; connect it, a manager
  signs in online, it uploads and downloads a fresh snapshot.
- Unsynced records older than 7 days: connect the till, let the queue drain, then check the **Offline exceptions** list.
- Browser till lost its data: sales not yet sent are gone with the browser storage; check the shift report for gaps (the
  server reports gaps in the device's sequence numbers).

## ZATCA signing on the till (OP-12, off until OP-0)

`POS_OFFLINE_ZATCA_DEVICE_SIGNING=false` by default. When the owner has ZATCA's confirmation and it is turned on, each
native till is its own EGS unit: the owner onboards it on the till with a FATOORA OTP (the key is made on the device and
never leaves it), the till signs simplified invoices offline, and after sync the server checks the ICV / PIH chain and
reports every invoice (`pos:offline-zatca-report`, every 5 minutes). The device list shows per till the chain gaps,
forks, unreported and rejected invoices. A fork (two invoices at one ICV) means the till's chain broke: contact the
native contractor; the invoices are still reported. Spec: OFFLINE-POS-SPEC.md section 13.1.
