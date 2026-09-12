# Feature Specification: A Daily Copy of the Database, Sent to Telegram

**Feature Branch**: `001-telegram-backup`

**Created**: 2026-09-12

**Status**: Draft

**Input**: User description: "Protect the ledger: an automatic daily backup of the whole database, a one-click download of the latest backup, and a full export/import so the office can move the ledger to another machine or restore it after a loss — with visible proof that a backup actually happened, and a restore that cannot run by accident on a live panel."

**Scope correction (2026-09-12)**: the office asked for the simple version first: **a copy of the
database, every day, sent to the office's Telegram chat by a bot**. Everything about restoring,
importing and accountant-facing exports was cut from this feature and is listed under Out of Scope
as its own future work.

## Clarifications

### Session 2026-09-12

- Q: Where should the nightly copy live outside the machine — a file the office downloads when it remembers, or does the panel send it somewhere by itself? → A: The panel sends it to the office's own Telegram chat every night, automatically, with a message stating which night the copy is from and its size.
- Q: How far back must the office be able to go? → A: The 30 most recent daily files, kept on the machine; older ones are pruned automatically. The durable record is the chat.
- Q: How much belongs in this first version? → A: The nightly copy of the database and its delivery to Telegram only. No restore screen, no import screen, no export bundle — those are separate features.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Every night, a copy of the database arrives in the chat (Priority: P1)

The panel copies the whole database by itself every night and sends the file to the office's own
Telegram chat through the office's bot, with a short message in Arabic saying which night the copy
is from and how big it is. The owner wakes up, opens Telegram on his phone, and last night's
database is there. Nobody had to remember anything, and nobody had to be at a computer.

**Why this priority**: This is the entire feature. The ledger is the business — every screen in the
panel is a way of reading it — and today there is no copy of it anywhere. A nightly copy that leaves
the machine on its own is what makes a dead disk a bad day instead of the end of the business.

**Independent Test**: Configure a bot and a chat, run one night's job against a populated database,
and confirm the file arrives in the chat with its message; then run it twice in one night and
confirm both copies arrive and neither replaces the other.

**Acceptance Scenarios**:

1. **Given** a configured bot and chat, **When** the nightly job runs, **Then** a file containing the whole database arrives in the chat, with a message naming the night and the size.
2. **Given** the job has never run, **When** the owner opens the panel's backup screen, **Then** it says plainly that no copy has been sent yet and offers to send one now.
3. **Given** the copy is made and the message is sent, **When** the owner opens the file in the chat, **Then** it is the database of that night, readable by whoever maintains the machine.

---

### User Story 2 - The office can see that it happened, and send one by hand (Priority: P2)

The owner opens one screen and sees the truth without digging: when the last copy was sent, how big
it was, whether it arrived, and — when it did not — why not. He can also press once to send a copy
now, because sometimes he wants today's books in the chat before doing something risky.

**Why this priority**: A backup the office cannot confirm is a backup the office does not trust, and
a failure nobody sees is the same as no backup at all. It depends on Story 1 existing and is
otherwise independent — the nightly job works with or without this screen.

**Independent Test**: Run one successful send and one failing send (a revoked bot), then open the
screen: both appear with their outcome, the reason is shown for the failure, and the manual send
produces a third entry that arrives in the chat.

**Acceptance Scenarios**:

1. **Given** a copy was sent last night, **When** the owner opens the screen, **Then** it shows the night, the size, and that it arrived.
2. **Given** the last attempt failed, **When** the owner opens the screen, **Then** the failure is shown with its reason, and the screen does not present an older copy as last night's.
3. **Given** the owner presses send now, **When** it succeeds, **Then** the file arrives in the chat and appears in the list as a manual send.
4. **Given** the newest successful copy is older than the office expects, **When** the screen is opened, **Then** that is said in plain words rather than left to be noticed.

---

### User Story 3 - Setting the destination up once (Priority: P3)

Before any of this can run, someone tells the panel where to send: the bot's credential and the chat
it should post to. The screen walks the owner through it, offers a test that says whether the chat is
reachable, and keeps the credential out of sight afterwards — it can be replaced, but never read
back out of the panel.

**Why this priority**: It is a one-time setup, and the two stories above depend on it — but it is
also the part that touches a secret, so it has to be right rather than merely present. It is last
because it happens once and is never used again.

**Independent Test**: Enter a bot credential and a chat, press test, and confirm the panel reports a
reachable chat; then enter a wrong credential and confirm the failure is reported plainly and nothing
is silently accepted.

**Acceptance Scenarios**:

1. **Given** an admin has a bot and a chat, **When** he enters them and sends a test, **Then** the panel confirms whether the chat is reachable and names what to correct when it is not.
2. **Given** the credential is saved, **When** the screen is opened again, **Then** it is not displayed, and replacing it is still possible.
3. **Given** a collector or a signed-out visitor, **When** they reach for this screen, **Then** they are refused.

---

### Edge Cases

- **The channel that stops accepting.** A revoked bot, a chat the bot was removed from, or no connection at three in the morning must never quietly become "no backup": the failure is recorded with its reason, it is reported to the chat as soon as the channel works again, and the panel keeps saying it.
- **A copy too big for one message.** When the file exceeds what one message accepts, it must still arrive complete, as ordered parts, with the message saying how many belong together.
- **A half-written copy.** A copy that failed while being made must never be sent as if it were whole, and must not be reported as a success.
- **Two copies in one night.** A manual send minutes before the nightly one must not overwrite or hide it.
- **The machine's clock and the office's expectations.** "Every day" means every day the panel ran; a machine that was off reports the gap rather than pretending the last copy is recent.
- **The wrong chat.** Changing the destination must be a deliberate act by an admin, and the change must be visible afterwards, so a copy cannot start going somewhere else unnoticed.
- **Disk pressure.** The machine keeps the most recent 30 files and prunes older ones; running low must be reported before copies start failing.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The panel MUST copy the whole database once a day, automatically, without anyone clicking, and without the office running a command.
- **FR-002**: The copy MUST be the complete database — every table the panel writes, including subscribers, routes, readings, invoices and their lines, payments and their allocations, cash handovers, expenses, tariffs, settings, users and the audit trail — in a form that can be used to restore the ledger on another machine by whoever maintains it.
- **FR-003**: The panel MUST send that copy, the same night, to the office's own Telegram chat through the office's bot, as a file, with no manual step.
- **FR-004**: The panel MUST send a short message in the office's language with each attempt: which night the copy is from, its size, and whether it arrived; a failure MUST be reported with its reason.
- **FR-005**: The panel MUST record every attempt — successful and failed — with the time, the size, who or what triggered it (the nightly job or an admin), and the reason for any failure.
- **FR-006**: The panel MUST show, on one admin screen, the most recent attempts and MUST state plainly when no copy has ever been sent, or when the newest successful one is older than the office's expectation.
- **FR-007**: An admin MUST be able to send a copy immediately, and that copy MUST be recorded and delivered exactly like a nightly one.
- **FR-008**: A failure MUST NOT remove, hide, damage or replace an earlier good copy, and MUST NOT be reported as a success.
- **FR-009**: The destination (the bot's credential and the chat to post to) MUST be configurable by an admin, MUST offer a test that reports whether the chat is reachable and what to correct when it is not, and MUST NOT show the credential back once it has been saved.
- **FR-010**: The panel MUST keep the most recent 30 copies on the machine and remove older ones automatically, and MUST always keep the newest one, even while pruning.
- **FR-011**: When a copy exceeds what one message accepts, the panel MUST deliver it complete as ordered parts and MUST state how many parts belong together.
- **FR-012**: Every screen and message in this feature MUST be admin-only or addressed to the office's own chat, and every string it shows MUST exist in both Arabic and English.
- **FR-013**: The panel MUST NOT send a copy it has not verified to be complete.

### Key Entities

- **Backup attempt**: one run of making and sending a copy — when it ran, what triggered it, the size of the copy, whether it was delivered, and the reason when it was not.
- **Destination**: the office's bot and the chat it posts to, with the credential kept out of sight once saved.
- **Copy**: the file itself — a complete copy of the database for one night, kept on the machine while it is one of the most recent 30, and living in the chat afterwards.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A copy of the database arrives in the office's chat for every night the panel ran, with no action by the office.
- **SC-002**: Any night without a delivered copy is visible in the panel and reported in the chat within the same night — zero silent gaps.
- **SC-003**: The owner can answer "when was the last copy, and did it arrive?" in under 10 seconds, in Arabic, from the panel or his phone.
- **SC-004**: Setting the destination up, from nothing to a confirmed test send, takes under 5 minutes for someone who has never done it.
- **SC-005**: An admin can put today's database in the chat on demand in under a minute.
- **SC-006**: No copy is ever shown or sent as a success unless it was verified complete.

## Out of Scope (this feature)

Named explicitly so nobody assumes they are covered, and kept for their own feature when the office
wants them:

- **Restoring from the panel.** Getting the ledger back onto a machine is done from the file in the
  chat by whoever maintains it. A restore screen — with a summary of what is inside the file, a
  deliberate confirmation, and a record of who did it — is its own feature.
- **Import / merge.** Nothing in this feature writes a file back into the panel.
- **Export bundles for the accountant.** Period registers in openable files are their own feature.
- **The photographed receipts.** Expense receipts are files on the machine, not rows in the
  database, so this copy does not include them. That is a real limitation and is stated in the
  feature's own words rather than left to be discovered during a restore.

## Assumptions

- **The office already uses Telegram, and it is the only outside service involved.** It supplies a
  bot and a chat, set up once by an admin following the screen; the panel creates no accounts
  anywhere and talks to nothing else. Where the chat's contents live afterwards — a phone, a
  laptop, Telegram's own history — is the office's practice, not a promise the panel makes.
- **The office does not run commands.** The nightly job runs from the machine's own scheduler,
  configured once when the panel is installed; the office's interactions are limited to the screen,
  the test, and the send-now button.
- **Thirty files on the machine, the chat is the record.** The office chose to keep the last 30
  copies locally and prune older ones, on the understanding that the durable copy is the one in the
  chat. The screen states this in plain words so nobody assumes a year is on the disk.
- **A copy is small because it is the database only.** Without the receipt photographs the file
  stays small enough to travel over the office's connection and to fit one Telegram message in
  normal use; the parts rule exists for the day that stops being true.
- **The database is the panel's own.** The machine that runs the panel is the only writer, so a copy
  taken at three in the morning is consistent on its own; no coordination with another system is
  needed.
- **Existing screens are reused.** The backup screen joins the other system screens (`Users`,
  `Settings`, `Audit log`) and follows the panel's existing look, language rules and money
  formatting; no new design language is introduced.
