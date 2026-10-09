# Ever PS Click & Collect – pickup time fork (PrestaShop 8)

Click & collect (store pickup) carrier for PrestaShop 8 / 9, with a **pickup time choice** designed for shops where customers rarely know exactly when they will come.

> This is an unofficial fork of [TeamEver/everpsclickandcollect](https://github.com/TeamEver/everpsclickandcollect). It is not an official Team Ever release.
> Original work © Team Ever, released under the [Academic Free License 3.0](LICENSE.md). The changes of this fork are released under the same license.

Declared for **PrestaShop 8.0.0 – 9.x**. Tested on **8.2.0** (classic theme) and **9.2.0** (Hummingbird and classic themes) with PHP 8.3. Not tested: PrestaShop 8.0.x, 8.1.x, 9.0.x, 9.1.x and PHP versions other than 8.3 (the code is checked for PHP 7.2 – 8.4 compatibility, without running it). PrestaShop 1.7 is not supported. Customer and back office texts: **French** (default) and **English**; languages without their own default texts get the French ones.

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
     - times cannot be left empty (an empty time would mean "any time");
     - today and tomorrow are shown as **Today / Tomorrow** (*Aujourd'hui / Demain*), other days as `ven. 9 oct.`;
     - overlapping or touching periods of the same day are merged (`14:00–16:00` + `15:00–17:00` → `14:00–17:00`).
3. **Warning box** (rounded box) when the time range is too wide and the order will probably **not be prepared in advance** (see *Prepare on arrival* below).
4. **Notes** below the pickup time, for every customer: one text, one note per line, the first line is shown as a title. A `{whatsapp}` variable shows a clickable WhatsApp link.
5. Pressing **Continue** checks everything again on the server: no choice, no period, past time, time outside the pickup hours… are refused with a clear message.

### Prepare on arrival

"Pick up now" orders are prepared right away. "Pick up later" orders are marked **Prepare on arrival** (not prepared in advance) when:

| Rule | Default | Example |
|---|---|---|
| First and last dates more than *N* days apart | on, N = 1 | Thu + Fri → prepared · Thu + Sat → prepare on arrival |
| Total time of the merged periods longer than *N* hours | on, N = 6 | 12:00–18:00 → prepared · 10:30–19:00 → prepare on arrival |

Both rules can be switched off. The customer sees the warning box while choosing.

### Back office

- **Order page**: "Click & collect pickup" block with the store, the pickup choice (`Pick up now · Ordered at …` or `Pick up later · Fri 9 Oct 14:00–17:00 / …`) and a **Prepare on arrival** badge.
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

**Upgrading**: upload the new zip over the installed module (no need to uninstall). Make a database backup first.

### Module lifecycle (3.4.1)

| Action | What happens to the data |
|---|---|
| Install | Creates what is missing (tables, settings, hooks, back office page, carrier). An existing carrier and existing data are reused. If a step fails, the install is rolled back and can simply be run again |
| Upgrade | Same steps, idempotent. If an upgrade step fails, the version is not changed and the upgrade can be run again once the cause is fixed |
| Disable | The carrier is deactivated (customers cannot choose click & collect while the module cannot ask for the store and time) |
| Enable | The carrier is reactivated. Enabling also repairs an interrupted install or upgrade |
| Uninstall / Reset | **Data is kept**: pickup choices of all orders, store stock, settings. The carrier is deactivated, never deleted, and reused by a reinstall |
| Delete all module data | Configuration page, last panel: tick the box and type `DELETE`. Uninstalls the module and permanently deletes the pickup choices of all orders, the store stock, the store hours and the settings. Orders and stores are not deleted; the carrier is only marked as deleted so orders keep it |

Opening the module configuration page also checks the database structure and repairs it if an upgrade was interrupted.

When the staff edits the carrier in *Shipping → Carriers*, PrestaShop creates a copy with a new id: orders of the old id are still recognised as click & collect orders.

The audit of these scenarios and the test runner are in [AUDIT.md](AUDIT.md) and `tests/lifecycle/` (never run the tests on a live shop; they only accept a database whose name ends with `_lc`). Do not copy the `tests/` folder to a shop.

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
| Closing time | 19:30 | Only used in the notes (`{closing}`) |
| Minute step | 15 | 5 / 10 / 15 / 20 / 30 / 60 |
| "Pick up now" time limit | 30 min | Shown in the "Pick up now" description |
| Bookable pickup days | 6 | Today and the next pickup days (days without pickup are not counted) |
| Do not prepare when dates are too far apart / Maximum days | on / 1 | See *Prepare on arrival* |
| Do not prepare when the total time is too long / Maximum total time | on / 6 h | Multiple of 0.5 h |
| WhatsApp link | — | e.g. `https://wa.me/33612345678`, used by `{whatsapp}` |
| Main color | `#1b82d6` | Selected choices, buttons, links |
| Warning box color | `#e8a33d` | Border of the warning box, background is a light shade |
| Notes text color | `#5f6f82` | Small notes |
| Notes, Warning | see below | Editable per language (French by default), one switch each |

Checks when saving: valid ranges without overlap, minutes on the minute step, closing time not earlier than the latest pickup time, valid exception lines, colors `#rrggbb`, WhatsApp link as a full address. A warning is shown when the maximum total time is longer than a day of pickup hours (the rule can then only apply to several days).

### Texts

| Text | Where | Default (French) |
|---|---|---|
| Notes | Below the pickup time, all customers. One note per line, first line = title | Merci de respecter l'horaire choisi.<br>L'affluence au magasin varie : nous ne pouvons pas garantir que votre commande sera prête dès votre arrivée.<br>Comme nous sommes souvent occupés avec les clients, nous ne pouvons pas toujours répondre au téléphone : pour changer d'horaire, laissez-nous un message sur {whatsapp}.<br>Pour un retrait entre {latest} et {closing}, choisissez {latest} et prévenez-nous à l'avance sur {whatsapp} : un collègue restera au magasin pour vous attendre. |
| Warning | Rounded box, only "Pick up later" with a time range too wide | La plage horaire choisie est large : nous ne préparerons peut-être pas votre commande à l'avance. Merci de votre compréhension. |

Variables: `{latest}` latest pickup time of the week · `{closing}` closing time · `{now_limit}` "Pick up now" time limit · `{whatsapp}` WhatsApp link.

---

## Technical notes

- Pickup choices are stored in `ps_everpsclickandcollect` (one row per cart): `pickup_mode` (`now` / `later`), `pickup_periods` (JSON), `pickup_prepare`, `pickup_summary`, plus the original `id_store`, `delivery_date`, `delivery_hour` columns (`delivery_hour` also receives the periods in the 3.3.0 format, so a downgrade still shows them).
- Lifecycle code: `lifecycle/` – `EverpsclickandcollectSchema` (tables), `EverpsclickandcollectCarrierManager` (carrier), `EverpsclickandcollectMigrator` (settings of older versions), `EverpsclickandcollectInstaller` (install / repair / uninstall / delete all data / enable / disable).
- Hooks: `displayCarrierExtraContent`, `actionValidateStepComplete` (blocks "Continue"), `displayOrderConfirmation`, `displayAdminOrderMain`, `displayPDFDeliverySlip`, `actionEmailSendBefore`, `actionOrderGridDefinitionModifier`, `actionOrderGridQueryBuilderModifier`.
- A hidden back office controller (`AdminEverPsClickAndCollectPickup`) saves the pickup time changed by the staff.
- PrestaShop 9 bundles theme CSS in a cache: after updating the module, clear the cache (Advanced parameters → Performance → Clear cache) so the new styles are used.
- The per-store stock field of the original module uses the old product page hook (`displayAdminProductsQuantitiesStepBottom`), which the new product page of PrestaShop 8.1+ and PrestaShop 9 no longer displays. Store stock can still be imported / exported as CSV.
- Orders saved by 3.2.0 / 3.3.0 (time slots) are still displayed.

### Changelog

- **3.4.5** – The 4 notes (NOTE, T2, T3, T1) are now one text "Notes" (one note per line, first line = title) with one switch; existing texts are merged automatically on upgrade (a note that was switched off is left out). Shorter explanations on the settings page.
- **3.4.4** – Settings page: one switch for the 4 notes (NOTE, T2, T3, T1); each switch explains where and when its text appears.
- **3.4.3** – Settings page: texts grouped in the order the customer sees them (notes, then warning box), the condition shown once per group, and each switch says what the text is ("Show text T1: last note").
- **3.4.2** – Back office shows "Pick up now" / "Pick up later" instead of the letters A / B.
- **3.4.1** – Lifecycle fixes (see [AUDIT.md](AUDIT.md)): uninstall and reset keep the data and never delete the carrier; explicit "Delete all module data" action; disabled module = carrier not offered; orders keep their pickup information after the carrier is edited; failed installs roll back and failed upgrades can be retried, enabling the module repairs them; upgrades from 2.x fixed; no duplicate carrier, store row or store address; declared compatibility PrestaShop 8.0.0 – 9.x.
- **3.4.0** – "Pick up now / Pick up later" with up to 3 prefilled periods; pickup hours per week day and part-day closures; "Prepare on arrival" rules; editable notes and warning box; WhatsApp link; color settings; staff can change the pickup time; delivery slip only (no invoice).
- **3.3.0** – Tabbed time slot picker, slots on several days, morning / afternoon mode.
- **3.2.0** – 30-minute time slots; PrestaShop 8.2 fixes: order confirmation block never displayed, cached carrier block overwriting the customer's choice, store id overwritten by the hours object, undefined variable and wrong language in the store email, inverted store stock condition, uninstall failure, PHP 8.2 deprecations, remote version check removed.
- **3.1.1** – Last Team Ever release (store and week day choice).

---

## Credits

Original module by [Team Ever](https://www.team-ever.com) – [product page](https://www.team-ever.com/prestashop-module-clickn-collect-gratuit/). You can support their free modules with a [donation](https://www.paypal.com/donate?hosted_button_id=3CM3XREMKTMSE).
