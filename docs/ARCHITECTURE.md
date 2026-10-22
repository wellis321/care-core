# Care Core: architecture

## Why one database

The older Staff, People and Team services each held part of the truth and copied the rest (names, references, numeric IDs
that point into another database). Nothing told one service when another changed, so a leaver could stay in a team, keep a
key worker role and keep a login.

Care Core keeps one database with real foreign keys. The three products are modules over the same `persons` table.

## Data model

```
organisations ─┬─ organisation_modules          which modules are on
               ├─ persons                        one permanent identity per human (uuid)
               │    ├─ employments               a stint of work: start_date, end_date, job, contract, line manager
               │    │    └─ employment_changes   what changed in a move, and from when
               │    ├─ support_episodes          a period of receiving support (NHS number, references)
               │    │    ├─ care_needs, person_contacts
               │    ├─ team_assignments          who is in which team, valid_from / valid_to
               │    ├─ key_worker_assignments    supported person ↔ staff person, valid_from / valid_to
               │    ├─ users                     a login; access_ends_on ends it on the right day
               │    └─ tasks                     onboarding / offboarding / reassign work
               ├─ person_profiles (1:1 with persons)   address, emergency contact, NI/bank (encrypted), photo, signature
               ├─ employment_details (1:1 with employments)   TUPE, working time, visa, apprenticeship, leave
               ├─ profile_changes                       what staff asked to change, awaiting approval
               ├─ registrations, supervisions, appraisals, learning_records
               ├─ teams                          hierarchy, staff_only flag
               ├─ checklist_templates (+ items)  what creates tasks
               ├─ outbox_events → webhook_deliveries ← webhook_endpoints
               ├─ audit_log                      append only
               └─ scim_tokens
```

"Active on date D" always means `start <= D AND (end IS NULL OR end >= D)`. The end date is the **last day**.

## Rules that are enforced (and tested)

- One active employment per person; a rehire starts after the previous one ended.
- One active place per person per team; a new stint cannot start before the last one ended; a staff member has one main team.
- Staff-only teams refuse people we support. A team cannot sit under itself or its own sub-teams (loops cannot hang the tree).
- Key workers must be current staff, and the person must be receiving support. Ending support ends their key worker links and
  team places.
- **Leaving** (`Lifecycle::leave`), in one transaction: ends the employment; ends team places (cancels any that had not started);
  ends key worker links and creates a "find a new key worker" task for each person; creates a "new line manager" task for each
  report; sets the login's `access_ends_on`; creates the offboarding checklist; writes the audit row and the events.
- **Moving** (`Lifecycle::move`): changes are dated and recorded; the old main team stint ends the day before and the new one
  starts on the effective date. Moves cannot be future-dated.
- **Joining** (`Lifecycle::join`): person (or an existing person for a rehire), employment, team place, optional login with a
  one-time invitation, onboarding checklist, audit row, event.

## Events

`Events::emit` writes to `outbox_events` inside the same transaction. `EventDelivery::run` (cron, every minute) fans events out
to matching `webhook_endpoints` and posts them signed with `X-CareCore-Signature: sha256=HMAC(secret, body)`, retrying with
backoff 1, 5, 30, 120 and 720 minutes. Receivers use the event id as an idempotency key. The envelope:

```json
{ "id": "uuid", "type": "employment.ended", "version": 1, "occurred_at": "2026-10-22T09:30:00.000Z",
  "organisation": "uuid", "subject": "person uuid", "data": { "employment": "uuid", "end_date": "2026-11-12" } }
```

Types: `person.created|updated`, `employment.started|changed|ended`, `team.created|updated|closed`,
`team_assignment.started|ended`, `support.started|ended`, `keyworker.started|ended`, `user.created`, `task.completed`.
Delivery is one function, so a message broker (for example RabbitMQ) can replace it later without changing any module.

## SCIM (Microsoft Entra)

`/scim/v2/Users` (bearer token). A SCIM user is a **person**; `active` means currently employed.
`POST` runs the joiner process (or a rehire). `PATCH active=false` (Entra sends the string `"False"`) and `DELETE` run the
leaver process and disable the login **now**. `active=true` rehires. The provider may update the name and job title (a title change is recorded as a mover change); teams, line manager, hours and everything else are owned here and ignored if sent.

## Staff records and self-service

`StaffRecord` holds the registry of fields: how each is checked, which are encrypted, and which a member of staff may change.
`self: direct` fields (phone, emergency contact) apply at once; `self: approval` fields (name, date of birth, address, NI, bank,
photo, signature) go into `profile_changes`. `ProfileChanges::canReview` allows the person's current line manager, a manager or
an administrator, and never the person themselves. Approving re-checks the value, writes it, audits the field names only, and
emits `person.updated` with field names (no values). Replacing a pending request withdraws the old one, and a rejected or
withdrawn picture is deleted from disk.

## Single sign-on

`Oidc` implements the authorisation code flow with PKCE for one Entra tenant. The id token must have an RS256 signature that
verifies against the tenant's published keys (cached for an hour), the right issuer, tenant and audience, be in date, and carry
this sign-in's nonce. The first sign-in finds the login by sign-in name (never the unverified email claim) and links it to the
object id (`tid:oid`); after that the object id decides. SCIM-created and invited logins become active when Microsoft confirms
them. Administrators keep a password even with `PASSWORD_LOGIN=false`.

## Roles

`admin` everything; `manager` people, staff lifecycle, teams, care, tasks, activity; `staff` colleagues, teams, their own tasks.
Care and health information requires `care.view`.

## Decisions to revisit

- Email for invitations and approval notifications; editing compliance records in place; files for certificates and contracts.
- Group-to-role mapping from Entra (today roles are set in Care Core).
- Moves dated in the future, and a scheduled-change view.
- Per-organisation encryption of the care tables, and read-access logging for care records.
