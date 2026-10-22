# Care Core

One system of record for the people in a care organisation: **staff, people we support, and the teams between them.**
Joiners, movers and leavers change everything together, in one transaction, and everything else listens.

It replaces the pattern where staff details are copied between an HR system, a rota, a training tool and a care
system, and nobody is sure which one is right when someone leaves.

Plain PHP 8.1+ and MySQL 8 / MariaDB 10.5+. No framework, no Composer at runtime.

## Importing

`scripts/export-old-systems.php` reads the older Staff, People or Team service database (read only) and writes an import file;
`scripts/import.php` (or Admin → Import data) checks it with a dry run, then imports. The importer uses the application's own
services, matches existing people, never overwrites, keeps history, and is safe to run again. Format and runbook: `/docs/import`,
`/docs/import-format`, `/docs/import-migrating`. Code is in `app/Modules/Import/`.

## API

A versioned REST API under `/api/v1` (60+ endpoints: people, staff lifecycle and records, compliance, teams, care, tasks, events,
webhooks, audit). Bearer API keys with scopes (created under Admin → Integrations), per-key rate limits, address allow-lists,
expiry, idempotent POSTs, cursor pagination, problem+json errors. It calls the same domain services as the web interface, so the
same rules, audit trail and events apply. The OpenAPI 3.1 document is generated from the route table at
`/api/v1/openapi.json`; guides and a generated reference are at `/docs/api`. Code is in `app/Api/`.

## Documentation

The full documentation is built in and public (no sign-in): **/docs** (36 pages: guides, administration, integrations, reference,
running it), plus **/about**, **/features** and **/security**. The pages live in `app/Views/docs/pages/` and are listed in
`app/Modules/Docs/Docs.php`; tests check that every page exists, every link works, search works, and every event the code can
emit is in the event catalogue.

## The ideas

1. **One person, many relationships.** A person is created once and never deleted. Being staff, being supported, being in a
   team or being someone's key worker are *relationships* with start and end dates. A rehire, a move, or someone returning to
   a team is a new row. History is never overwritten.
2. **One database, modules.** People (always on), Staff, Teams, Care. Real foreign keys, real transactions. Modules are
   switched on per organisation.
3. **Lifecycle in one transaction.** `Lifecycle::join / move / leave` change the employment, team places, key worker links,
   the login, checklists, the audit log and the outbox together. A leaver cannot be half-leaving.
4. **Effective dates, not jobs.** A login stops working the day after the last day because it is *checked against that date*,
   not because a scheduled job remembered. Team places and key worker links end on the last day.
5. **Events out, SCIM in.** Every change writes an outbox event (identifiers and dates only, never names or health details),
   delivered as signed webhooks. Microsoft Entra can push joiners and leavers in through SCIM.
6. **Everything a staff record needs.** Address, emergency contact, National Insurance and bank details (encrypted), photo and
   signature; terms of employment (TUPE, working time, apprenticeship, visa, leave); registrations, supervisions,
   appraisals and learning. Staff keep their own details up to date from **My profile**: phone and emergency contact save at
   once, everything else waits for their line manager (or HR) to approve, and a drawn or uploaded signature works the same way.
7. **Sign in with Microsoft.** OpenID Connect against one Entra tenant (authorisation code with PKCE, tokens fully checked),
   plus SCIM for joiners and leavers. Nobody is created by signing in.
8. **A safety net.** `scripts/reconcile.php` proves the data is consistent and reports (or fixes) anything that is not.

See `docs/ARCHITECTURE.md` for the data model and the rules.

## Run it

```bash
cp .env.example .env                     # fill in DB_*; set DEMO_MODE=true for a demo database whose name contains "demo"
php scripts/migrate.php                  # creates the tables (add --fresh to rebuild a demo or test database)
DEMO_MODE=true php scripts/demo-reset.php   # optional: a fictional care organisation to look around
php -S localhost:8990 -t public public/index.php
```

Demo sign-ins (password `DemoPass2026!`): `admin@demo.lochview.test`, `manager@demo.lochview.test`, `staff@demo.lochview.test`.

## Test it

```bash
DB_NAME=care_core_test php tests/run.php          # domain tests (clean database each run; refuses non-"test" databases)
DB_NAME=care_core_demo DEMO_MODE=true php scripts/demo-reset.php
php -S localhost:8990 -t public public/index.php &
python3 tests/http_flows.py                       # end-to-end through the web interface and SCIM
```

## Cron

```
* * * * *  php /path/to/scripts/deliver-events.php      # send queued webhooks, with retries
15 3 * * * php /path/to/scripts/reconcile.php --fix     # nightly consistency check (omit --fix to only report)
15 3 * * * php /path/to/scripts/demo-reset.php           # demo only
```

## Microsoft Entra

1. Register an application in Entra: *Accounts in this organisational directory only*, with a **Web** redirect address of
   `https://YOUR-SITE/login/entra/callback`. Create a client secret.
2. Put `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID` and `ENTRA_CLIENT_SECRET` in `.env` (and `APP_URL`, or `ENTRA_REDIRECT_URI`).
   A "Sign in with Microsoft" button appears. `PASSWORD_LOGIN=false` turns passwords off for everyone except administrators.
3. For joiners and leavers, add an enterprise application with automatic provisioning using the SCIM address and a token from
   Admin → Integrations.

People are matched by their Microsoft sign-in name the first time and by their stable object id after that, so changing an
email address never moves an account to someone else. Care Core creates nobody at sign-in.

Also set `APP_KEY` (32 random bytes, base64). It encrypts National Insurance and bank details.

## Security notes

- Passwords: `password_hash`; sign-in is rate limited per account and per address; sessions are regenerated on sign-in and
  re-checked on every request (a leaver's session ends after their last day).
- CSRF token on every POST. Output is escaped. CSP, frame and content-type headers on every response.
- Every query is parameterised and every read is scoped by organisation. Tests prove one organisation cannot see another.
- Webhook and integration addresses are checked (https only, no internal or metadata addresses).
- SCIM tokens and invitation tokens are stored only as hashes and shown once.
- National Insurance and bank details are encrypted in the database (AES-256-GCM) and only shown to people who manage staff and to the person themselves (masked). Audit rows and events name the fields changed, never the values.
- Photos and signatures are stored outside the web root, checked as real images, and only served to people allowed to see them.
- Self-service changes need approval from the line manager or HR, and nobody can approve their own.
- Health and care information lives in its own tables and is only shown to roles with `care.view`.

## Not built yet

Email sending (invitations and password resets are links shown once to an administrator, and approvals are not emailed), editing a compliance record in place (add and
remove only), documents and certificates (files), a leave calendar and booking, MFA for password sign-in, subject access
export and retention tools, future-dated moves, import from the three older apps.
