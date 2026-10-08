## Prestashop 1.7 & 8 free click & collect module
This free module allows you to convert stores to click & collect delivery method on Prestashop 1.7 & 8

[You can make a donation to support the development of free modules by clicking on this link](https://www.paypal.com/donate?hosted_button_id=3CM3XREMKTMSE)

## How to enable click & collect store
First, make sure you have at least one store created in your Prestashop store.
Select the authorized store (s) for the click & collect, the module will take care of retrieving the name of the store and its opening hours
The customer will be able to select the store in which he will collect his order. Optionally, he can choose the day of the week he will go to the store.
The store and selected day information will appear in the following locations:
- order confirmation
- bill
- delivery form
- order administration

## Contact and product sheet
You can contact us on our site https://www.team-ever.com
This free Prestashop Click'n Collect module is available for everyone also at this URL
https://www.team-ever.com/prestashop-module-clickn-collect-gratuit/
![Delivery click'n collect method](https://i0.wp.com/www.team-ever.com/wp-content/uploads/2021/04/choix-click-and-collect-prestashop.jpg?fit=897%2C884&ssl=1)

## 3.2.0 (unofficial fork)

This version is a fork of [TeamEver/everpsclickandcollect](https://github.com/TeamEver/everpsclickandcollect), not an official Team Ever release. Original work © Team Ever, AFL-3.0.

- Pickup time slots: customers choose a pickup **date** and one or more **time slots** (30 min by default) during checkout. Slots are built from the store opening hours (Shop parameters > Contact > Stores), e.g. `09:00 - 19:00`, `9h-12h / 14h-18h30`, `09:00AM - 07:00PM`.
- Settings: slot length, minimum preparation time, bookable days ahead, max orders per slot, max slots per order, closed dates.
- "Continue" on the shipping step is blocked until a valid date and slot are chosen (server-side check).
- Pickup date and time shown on order confirmation, back office order page, invoice / delivery slip PDF, emails, and as a filterable "Pickup" column in the back office order list.
- PrestaShop 8.2 fixes: order confirmation block never displayed, stale cached carrier block, `displayAdminOrderMain`, undefined variable in store email, PHP 8.2 deprecations, uninstall failure, remote version check removed.

## 3.3.0

- New checkout layout: store list, day tabs with arrows, rounded slot buttons in the theme color, legend (available / selected / full / not available) and a summary of the chosen pickup times.
- Slots can be selected on several days (e.g. this afternoon and tomorrow morning).
- New "Time slot mode" setting: fixed length slots, or whole opening periods ("Morning 09:00 - 12:00", "Afternoon 14:00 - 19:00"). A period stays bookable on the same day while it can still be prepared in time.
- The custom checkout message (e.g. a WhatsApp link for other pickup times) is now shown below the time slots.
- Slot capacity counts every order overlapping a slot, so bookings made in another mode or slot length are respected.
- Orders saved by 3.2.0 are still read and displayed correctly.

## 3.4.0 (unofficial fork)

Pickup time redesigned: customers are rarely sure when they will come, so instead of booking one slot they choose:

- **A. Pick up now** – only offered today during today's pickup hours; message T4.
- **B. Pick up later** – up to 3 periods `date HH:MM – HH:MM` (hour + minute pickers, minute step 5/10/15/20/30/60). Prefilled with today from now to the end of the pickup hours; an added line takes the next pickup day, whole pickup hours. The customer can change or clear them. Overlapping or touching periods of the same day are merged.

**Pickup hours** are set per week day in the module (one or several ranges, e.g. `10:30-14:00, 16:00-19:00`; empty = no pickup that day), independently from the store opening hours. "No pickup on these dates" closes a whole date (`2026-12-25`) or part of it (`2026-12-24 14:00-19:00`).

Orders choosing B are **prepared on arrival** (not in advance) when no period is given, when the first and last dates are more than N days apart, or when the merged periods last more than N hours (both rules can be switched off). Messages T1–T6 are editable per language (English, French) and can be switched off; variables `{latest}` (latest pickup time of the week), `{closing}`, `{now_limit}`.

Back office: "A · …" / "B · …" with a "Prepare on arrival" badge on the order page and in the order list; staff can change the pickup time of an order (any date). The delivery slip shows the pickup time and the "Prepare on arrival" flag; nothing is printed on invoices. Orders saved by 3.2.0 / 3.3.0 are still displayed.
