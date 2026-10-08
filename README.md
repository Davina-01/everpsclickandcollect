# Ever PS Click & Collect – pickup time fork (PrestaShop 8)

Click & collect (store pickup) carrier for PrestaShop 1.7 / 8, with a **pickup time choice** designed for shops where customers rarely know exactly when they will come.

> This is an unofficial fork of [TeamEver/everpsclickandcollect](https://github.com/TeamEver/everpsclickandcollect). It is not an official Team Ever release.
> Original work © Team Ever, released under the [Academic Free License 3.0](LICENSE.md). The changes of this fork are released under the same license.

Tested on **PrestaShop 8.2.0** (classic theme). Customer and back office texts: **English** and **French**.

---

## Features

### For the customer (checkout, "Shipping method" step)

1. **Store** – the customer chooses the pickup store (selected automatically when there is only one).
2. **Pickup time** – the customer must choose one of:
   - **Pick up now** – "I am on my way, or I can come as soon as the order is ready". Only offered today, during today's pickup hours.
   - **Pick up later** – up to **3 periods** `date  HH:MM – HH:MM` when they may come:
     - prefilled with *today, from now to the end of the pickup hours* (or the next pickup day if today is over);
     - **+ Add another time you might come** adds the next pickup day, with its whole pickup hours;
     - changing the date keeps the times when they are still possible that day, otherwise takes that day's default times;
     - hour and minute pickers (wheel on phones, typing on computers); only pickup hours can be chosen, past times are disabled;
     - the customer can clear the times (no time given);
     - overlapping or touching periods of the same day are merged (`14:00–16:00` + `15:00–17:00` → `14:00–17:00`).
3. **Warning box** (rounded box) when the order will probably **not be prepared in advance**:
   - **T5** – "Pick up later" without any time;
   - **T6** – time range too wide (see *Prepare on arrival* below).
4. **Small notes** below the pickup time, for every customer (title, then **T2**, then **T1** last). A `{whatsapp}` variable shows a clickable WhatsApp link.
5. Pressing **Continue** checks everything again on the server: no choice, incomplete period, past time, time outside the pickup hours… are refused with a clear message.

### Prepare on arrival

"Pick up now" orders are prepared right away. "Pick up later" orders are marked **Prepare on arrival** (not prepared in advance) when:

| Rule | Default | Example |
|---|---|---|
| No period given | always on | — |
| First and last dates more than *N* days apart | on, N = 1 | Thu + Fri → prepared · Thu + Sat → prepare on arrival |
| Total time of the merged periods longer than *N* hours | on, N = 6 | 12:00–18:00 → prepared · 10:30–19:00 → prepare on arrival |

The two last rules can be switched off. The customer sees the matching warning box (T5 / T6) while choosing.

### Back office

- **Order page**: "Click & collect pickup" block with the store, the pickup choice (`A · Pick up now · Ordered at …` or `B · Fri 9 Oct 14:00–17:00 / …`) and a **Prepare on arrival** badge.
- **Change pickup time** on the order page (e.g. the customer asked on WhatsApp to come another day). Any date can be set; "Prepare on arrival" is recalculated.
- **Order list**: "Pickup" column, filterable by date (type `2026-10-09`).
- **Delivery slip** (PDF used to prepare the order): pickup choice and a "Prepare on arrival" line. Nothing is printed on **invoices**.
- **Emails**: the pickup choice is added after the carrier name in the order emails; the optional email sent to the store also contains it.

---

## Installation

1. Download the module zip (folder `everpsclickandcollect/` at the root of the zip).
2. Back office → **Modules → Module Manager → Upload a module**.
3. Create at least one store: **Shop parameters → Contact → Stores**.
4. Open the module configuration and set the options below.
5. PrestaShop 8 also ships a carrier named "Click and collect": disable it in **Shipping → Carriers** to avoid two carriers with the same name.

**Upgrading**: upload the new zip over the installed module. Do **not** uninstall first: uninstalling deletes the table holding the pickup choices of all orders.

---

## Configuration

### Settings panel

| Setting | Description |
|---|---|
| Allowed click and collect stores | Stores the customer can choose |
| Ask for pickup time | Show the "Pick up now / Pick up later" choice |
| Custom message on order tunnel | Only shown when the pickup time is not asked |
| Stock per store, store images, email to the store | Original module features |

### Pickup time panel

| Setting | Default | Description |
|---|---|---|
| Pickup hours: Monday … Sunday | 10:30-19:00 Mon–Sat, Sunday empty | One or several ranges per day, e.g. `10:30-14:00, 16:00-19:00`. Empty = no pickup that day. Independent from the store opening hours |
| No pickup on these dates | — | One per line. Whole day `2026-12-25`, part of a day `2026-12-24 14:00-19:00` |
| Closing time | 19:30 | Only used in text T1 (`{closing}`) |
| Minute step | 15 | 5 / 10 / 15 / 20 / 30 / 60 |
| "Pick up now" time limit | 30 min | Shown in the "Pick up now" description |
| Bookable pickup days | 6 | Today and the next pickup days (days without pickup are not counted) |
| Do not prepare when dates are too far apart / Maximum days | on / 1 | See *Prepare on arrival* |
| Do not prepare when the total time is too long / Maximum total time | on / 6 h | Multiple of 0.5 h |
| WhatsApp link | — | e.g. `https://wa.me/33612345678`, used by `{whatsapp}` |
| Main color | theme color | Selected choices, buttons, links |
| Warning box color | orange | Border of the warning box, background is a light shade |
| Notes text color | grey | Small notes |
| Texts NOTE, T2, T1, T5, T6 | see below | Editable per language, each can be switched off |

Checks when saving: valid ranges without overlap, minutes on the minute step, closing time not earlier than the latest pickup time, valid exception lines, colors `#rrggbb`, WhatsApp link as a full address. A warning is shown when the maximum total time is longer than a day of pickup hours (the rule can then only apply to several days).

### Texts

| Code | Where | Default (French) |
|---|---|---|
| NOTE | Title of the small notes | Merci de respecter l'horaire choisi. |
| T2 | Note | L'affluence au magasin varie : nous ne pouvons pas garantir que votre commande sera prête dès votre arrivée. Comme nous sommes souvent occupés avec les clients, nous ne pouvons pas toujours répondre au téléphone : pour changer d'horaire, laissez-nous un message sur {whatsapp}. |
| T1 | Last note | Pour un retrait entre {latest} et {closing}, choisissez {latest} et prévenez-nous à l'avance sur {whatsapp} : un collègue restera au magasin pour vous attendre. |
| T5 | Warning box, no time given | Vous n'avez pas indiqué d'heure d'arrivée : nous ne préparerons peut-être pas votre commande à l'avance. Merci de votre compréhension. |
| T6 | Warning box, time range too wide | La plage horaire choisie est large : nous ne préparerons peut-être pas votre commande à l'avance. Merci de votre compréhension. |

Variables: `{latest}` latest pickup time of the week · `{closing}` closing time · `{now_limit}` "Pick up now" time limit · `{whatsapp}` WhatsApp link.

---

## Technical notes

- Pickup choices are stored in `ps_everpsclickandcollect` (one row per cart): `pickup_mode` (`now` / `later`), `pickup_periods` (JSON), `pickup_prepare`, `pickup_summary`, plus the original `id_store`, `delivery_date`, `delivery_hour` columns.
- Hooks: `displayCarrierExtraContent`, `actionValidateStepComplete` (blocks "Continue"), `displayOrderConfirmation`, `displayAdminOrderMain`, `displayPDFDeliverySlip`, `actionEmailSendBefore`, `actionOrderGridDefinitionModifier`, `actionOrderGridQueryBuilderModifier`.
- A hidden back office controller (`AdminEverPsClickAndCollectPickup`) saves the pickup time changed by the staff.
- Orders saved by 3.2.0 / 3.3.0 (time slots) are still displayed.

### Changelog

- **3.4.0** – "Pick up now / Pick up later" with up to 3 prefilled periods; pickup hours per week day and part-day closures; "Prepare on arrival" rules; editable notes and warning box; WhatsApp link; color settings; staff can change the pickup time; delivery slip only (no invoice).
- **3.3.0** – Tabbed time slot picker, slots on several days, morning / afternoon mode.
- **3.2.0** – 30-minute time slots; PrestaShop 8.2 fixes: order confirmation block never displayed, cached carrier block overwriting the customer's choice, store id overwritten by the hours object, undefined variable and wrong language in the store email, inverted store stock condition, uninstall failure, PHP 8.2 deprecations, remote version check removed.
- **3.1.1** – Last Team Ever release (store and week day choice).

---

## Credits

Original module by [Team Ever](https://www.team-ever.com) – [product page](https://www.team-ever.com/prestashop-module-clickn-collect-gratuit/). You can support their free modules with a [donation](https://www.paypal.com/donate?hosted_button_id=3CM3XREMKTMSE).
