<h2>The automated tests</h2>
<p>Care Core ships with a test suite that needs nothing installed beyond PHP and a test database:</p>
<pre><code>DB_NAME=care_core_test php tests/run.php            # all domain tests
DB_NAME=care_core_test php tests/run.php leaver    # only tests whose name contains "leaver"</code></pre>
<p>The runner rebuilds the test database from <code>schema.sql</code> at the start of every run and refuses to run against any database whose name does not contain “test”. Each test makes its own organisation, so tests never depend on each other.</p>
<h3>What is covered</h3>
<ul>
<li><strong>Lifecycle:</strong> the joiner, mover and leaver processes, all-or-nothing behaviour, rehires, events with no personal details, login access ending on the right day.</li>
<li><strong>Teams and care:</strong> hierarchy loops, stints, staff-only teams, key worker rules, support episodes.</li>
<li><strong>Staff records:</strong> field checks, encryption at rest, no values in logs or events, section isolation, self-service and approval (who can and cannot review), pictures and signatures, compliance records, what is due soon.</li>
<li><strong>Consistency and events:</strong> the checks and their fixes, webhook signing, retries and filters, address rules.</li>
<li><strong>SCIM:</strong> create, patch with Entra’s quirks, deprovision, reprovision.</li>
<li><strong>Microsoft sign-in:</strong> a valid token, and every kind of forgery and replay (wrong issuer, audience, tenant, nonce, expiry, algorithm, tampering, state), account matching rules, no account creation.</li>
<li><strong>Organisations:</strong> one organisation cannot see or approve another’s.</li>
</ul>
<p>There are also end-to-end checks that drive the real web interface (sign-in, lock-out, forms, uploads, access control, SCIM over HTTP, escaping, security headers):</p>
<pre><code>DB_NAME=care_core_demo DEMO_MODE=true php scripts/demo-reset.php
php -S localhost:8990 -t public public/index.php &amp;
python3 tests/http_flows.py</code></pre>

<h2>The demo</h2>
<p><code>php scripts/demo-reset.php</code> drops every table, rebuilds the schema and seeds a fictional organisation, <strong>using the application’s own services</strong> (so the demo also proves they work). It refuses to run unless <code>DEMO_MODE=true</code> <em>and</em> the database name contains “demo”.</p>
<p>What you get: “Loch View Care (Demo)”, with nine teams in a hierarchy, sixteen staff (including a rehire, a former member of staff, someone leaving in three weeks and someone starting in nine days), nine people we support (one whose support has ended), key workers, needs and contacts, registrations (some due soon, one lapsed), supervisions, appraisals, training, a TUPE transfer, a visa, and a pending request from a member of staff waiting for their manager. The three sign-in accounts are in <a href="/docs/signing-in">Signing in</a>.</p>
<p>All names, numbers and records are invented. The National Insurance and bank numbers are obvious placeholders.</p>
