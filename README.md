# Care Core

One system of record for the people in a care organisation: **staff, people we support, and the teams between them.**
Joiners, movers and leavers change everything together, in one transaction, and everything else listens.

It replaces the pattern where staff details are copied between an HR system, a rota, a training tool and a care
system, and nobody is sure which one is right when someone leaves.

Plain PHP 8.1+ and MySQL 8 / MariaDB 10.5+. No framework, no Composer at runtime.

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
6. **A safety net.** `scripts/reconcile.php` proves the data is consistent and reports (or fixes) anything that is not.

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

## Security notes

- Passwords: `password_hash`; sign-in is rate limited per account and per address; sessions are regenerated on sign-in and
  re-checked on every request (a leaver's session ends after their last day).
- CSRF token on every POST. Output is escaped. CSP, frame and content-type headers on every response.
- Every query is parameterised and every read is scoped by organisation. Tests prove one organisation cannot see another.
- Webhook and integration addresses are checked (https only, no internal or metadata addresses).
- SCIM tokens and invitation tokens are stored only as hashes and shown once.
- Health and care information lives in its own tables and is only shown to roles with `care.view`.

## Not built yet

Single sign-on login (OpenID Connect), email sending (invitations are links shown once), professional registrations,
supervision and training (these belong in a Compliance module on the same person record), subject access export and
retention tools, MFA, import from the three older apps.
