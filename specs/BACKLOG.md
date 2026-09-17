# Backlog: decided, not yet specified

Decisions the office has made that belong to a feature of their own. Kept here so a choice made in
conversation is not re-litigated or forgotten — a decision is cheap to record and expensive to
reconstruct six months later.

Each entry names what was decided, why, and where it stands.

---

## The reading cycle (decided 2026-09-12, by the owner)

A reading that looks wrong — a jump far above the house's own average, or a zero where the meter was
moving — must **warn and require a written acceptance from the office before it is invoiced**. Today it
warns and the invoice is raised anyway; the office's choice is to make the acceptance a recorded act,
phrase it as "the office accepts this reading, here is why", and keep the invoice from being produced
until it happens.

The edge cases are why this is its own piece of work: a collector on a route with no signal, a reading
accepted by phone, an acceptance that arrives after the cycle closed.

Decided at the same time, and also its own work:

- **A collector's correction window.** Today a collector who mistypes a reading must telephone the
  office; the office corrects it (with a reason, once the cycle is closed). A same-day correction path
  for the person who took the reading is wanted — with the original value kept.
- **A printed, exportable history for a reading.** The office wants to hand a subscriber's reading
  history to whoever asks, in the same shape as the receipts and invoices it already prints.

---

## Deferred from feature 001 (the nightly Telegram copy)

Recorded in `specs/001-telegram-backup/spec.md` under **Out of Scope**, repeated here so the backlog is
the single place these live:

- **Restore from the panel** — a screen that reads an archive, summarises what is inside it, requires a
  deliberate confirmation, and records who restored what. Today a restore is done from the file in the
  chat by whoever maintains the machine.
- **Import / merge** — nothing writes an archive back into a live ledger.
- **The accountant's export bundle** — period registers as openable files, with Arabic headings.
- **The photographed expense receipts** — files on the machine, not rows in the database, so a database
  copy does not include them. Named explicitly so nobody assumes a restore brings them back.

---

## Registered earlier, not yet built

- **WhatsApp reminders for overdue subscribers** — bulk `wa.me` links with Arabic/English templates and
  a per-subscriber "reminded on" record. Chosen in conversation as the highest-value next feature after
  the daily screen; not yet specified.
- **Bulk actions on the subscriber list** — amperage, subscription type, area for a whole street, plus
  a quick jump to any subscriber from anywhere.
- **A first-run setup wizard** — company, kWh price, lira rate, areas, users, so a fresh installation is
  not a blank panel.
- **A backup of the receipt photographs** — the half of the ledger that lives outside the database.

---

## Rejected: the audit trail and the reconciliation screen (2026-09-12)

Built, then turned down by the owner the same day: *"the idea complicated the program as a whole, so I
do not want it."* It was a change trail on every value that prices an invoice, eight arithmetic checks
over the ledger, an admin screen listing what does not add up, and a nightly check that reports a red to
the office chat.

Recorded here so it is not quietly proposed again — and so, if the concern ever returns, it comes back
as something smaller and plainer than what was rejected. The work itself is parked outside the
repository: `~/Downloads/002-ledger-integrity-rejected.patch`, and in a git stash named
*"002 ledger integrity + audit trail (rejected by the office: too much complexity)"*. Nothing of it is
in the program.

---

## Deferred: making the panel easier day to day (2026-09-15)

The office asked what would make the program easier to use, and said «نصائح جيدة بس لبعدين» — good
advice, but later. Recorded here so it is picked up deliberately rather than rediscovered. Ordered by
what it costs the office today, not by what is interesting to build.

**Three small things that are risky to leave:**

- **The nightly backup never runs.** The schedule is registered but the crontab is empty, so nothing
  scheduled has ever fired: `* * * * * cd <project> && php artisan schedule:run >> /dev/null 2>&1`.
- **Action buttons on the panel need a sweep.** In the office's browser a `wire:click` never reached
  Livewire (twenty clicks, no request, no error) while the same page's file input worked; the import's
  apply was moved to a native form for that reason. If the fault is general, every action button is
  dead for him — exports, editing a handover, saving settings. One minute to check: press «تصدير CSV»
  on the subscriber list.
- **A restore has never succeeded.** The dump is written and verified, but restoring died on MySQL
  permissions (the app's user cannot create a database). A backup that has never been restored is a
  hope, not a backup.

**Small work, large daily saving:**

- **Focus + Enter on the reading field.** The office enters dozens of slips a day; today each one is a
  click into the field and a click on save.
- **A daily summary to Telegram.** One message at closing: collected today, handed in, in the office,
  spent, how many households are behind. The bot and the schedule already exist.
- **WhatsApp reminders for the overdue list**, with the amount and the date, and a record of who was
  sent what so nobody is chased twice. The WhatsApp service already exists.
- **Order the reading list the way the street is walked**, not alphabetically, so a collector does not
  double back.

**Bigger, but the street asks for it:**

- **A data-entry role.** One role exists, so the computer cannot be handed to an employee without also
  handing over the money settings, the expenses and deletion.
- **The collector app working without a signal** — save the reading or the payment locally and sync
  when the phone finds the network again.
