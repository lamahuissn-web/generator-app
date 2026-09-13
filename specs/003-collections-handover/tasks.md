# Tasks — 003 التحصيل والتسليم

Visual contract: `~/Downloads/collections-handover-mockup.html` (frozen; every figure there is real data).

## Domain (read-only additions — no new rules)

- [x] T001 `CollectionService::collectorDocuments($collector, $from, $to, $method, $search)` —
      one row per receipt: date, time, number, subscriber (+meter), route, amount, method label +
      lira detail, settled invoices, note, recorded-by, day.
- [x] T002 `CollectionService::collectorAuditTrail($collector)` — the append-only log: every audit
      event for this collector's subjects (created/updated/deleted/reversed) joined to the live
      movement where one exists, with the balance after it and the actor; «غير مسجّل» when unknown.

## Screen

- [x] T003 `app/Livewire/Admin/Pages/Collections.php` — period bar + presets, collectors table,
      selection, four tabs, the period-cut toggle, the handover modal (create/edit/delete),
      the collector CSV export.
- [x] T004 `resources/views/livewire/admin/pages/collections.blade.php` — the frozen layout,
      built from `<x-admin.stat>` / `<x-admin.table>` / `<x-admin.cell>` / `<x-admin.field>`.
- [x] T005 `AdminNav` — one entry «التحصيل والتسليم» replacing the three; keep the group.
- [x] T006 `routes/admin.php` — redirects from the three old routes to the new screen.
- [x] T007 `lang/ar.json` + `lang/en.json` — the new keys (both catalogues).

## Integrity

- [x] T008 `TwoMonthsSeeder` — wipe `audit_logs` too (the sample carried rows pointing at payments
      that no longer exist) + its test asserts it.

## Tests

- [x] T009 Period scoping of the headline figures; `بيده = انحصّل − سلّم` per row.
- [x] T010 The handover guard still refuses more than the collector holds; edit may raise it back.
- [x] T011 The three old routes redirect (302) to the new screen with collector/period preserved.
- [x] T012 The audit table groups by day with its subtotal and carries invoice + "سجّلها".
- [x] T013 The audit log shows the balance after each event and survives a deleted handover.
- [x] T014 The period-cut toggle shows the opening balance as its first row.
- [x] T015 Print links keep working; the collector portal's «تقرير التحصيل» link still resolves.

## Verify

- [x] T016 Full suite green; old page-level tests re-pointed at the merged screen.
- [x] T017 Rendered on the office's data (light + dark) and shown to the office before any commit.

## Done — 2026-09-13

- Domain: `CollectionService::collectorDocuments()` + `collectorAuditTrail()` + `lastHandoverByCollector()`.
- Screen: `app/Livewire/Admin/Pages/Collections.php` + `resources/views/livewire/admin/pages/collections.blade.php`.
- One nav entry «التحصيل والتسليم»; the three old paths redirect (collector, range and all-time preserved);
  the two printed documents unchanged. The old page components and their views, plus HandoverForm and
  HandoversTable, are deleted — the registry no longer describes a handovers screen.
- `TwoMonthsSeeder` now wipes `audit_logs` with the ledger it names.
- Tests: `tests/Feature/CollectionsTest.php` — 17 tests (scopes, the dossier, the guard, write/edit/delete,
  the audit table's grouping and invoices, the recorder labels, the log surviving a deletion, the
  period-cut opening balance, the three redirects, admin-only, both prints, both spreadsheets).
  The old page-level tests were re-pointed at the merged screen.
- **479 passed (2692 assertions)**; the page renders HTTP 200 on the office's own data in all four tabs.

### Bug found by the merge

`CollectionService::collectorLedger()` filed a settlement by `created_at` (when it was typed in) while
every period figure counts it by `handover_date` (when the cash moved). A settlement entered late for
the 5th therefore appeared inside today's range in the ledger and outside it in «سلّم». The ledger now
uses the business date, like the rest of the program.
