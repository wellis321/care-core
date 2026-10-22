<p>Care Core uses one MySQL (or MariaDB) database with real foreign keys. Every table that holds an organisation’s data carries <code>organisation_id</code>. Public identifiers are UUIDs; internal keys are integers. The canonical definition is <code>database/schema.sql</code>.</p>

<h2>The shape of it</h2>
<pre><code>organisations
 ├─ organisation_modules          which modules are switched on
 ├─ persons                        one permanent identity per human
 │    ├─ person_profiles           1:1  address, emergency contact, NI/bank (encrypted), photo, signature
 │    ├─ employments               a stint of work: dates, job, contract, hours, line manager
 │    │    ├─ employment_changes   what changed in a move, and from when
 │    │    └─ employment_details   1:1  TUPE, working time, visa, apprenticeship, leave
 │    ├─ support_episodes          a period of receiving support
 │    │    ├─ care_needs
 │    │    └─ person_contacts
 │    ├─ team_assignments          who is in which team, valid_from / valid_to
 │    ├─ key_worker_assignments    supported person ↔ staff person, valid_from / valid_to
 │    ├─ registrations, supervisions, appraisals, learning_records
 │    ├─ profile_changes           staff requests waiting for approval
 │    ├─ tasks                     joiner / mover / leaver / reassign work
 │    └─ users                     a login (access_ends_on, oidc_subject)
 ├─ teams                          hierarchy (parent_team_id), staff_only
 ├─ checklist_templates / _items   what creates tasks
 ├─ outbox_events → webhook_deliveries ← webhook_endpoints
 ├─ audit_log                      append only
 └─ scim_tokens, login_attempts, schema_migrations</code></pre>

<h2>The tables</h2>
<div class="tablewrap"><table>
<thead><tr><th>Table</th><th>Holds</th><th>Key points</th></tr></thead>
<tbody>
<tr><td><code>organisations</code></td><td>Each tenant.</td><td>Everything else belongs to one.</td></tr>
<tr><td><code>persons</code></td><td>A human, once.</td><td>Names, date of birth, email, phone, notes. Never deleted.</td></tr>
<tr><td><code>person_profiles</code></td><td>Personal details that belong to the person.</td><td>National Insurance and bank columns hold encrypted text.</td></tr>
<tr><td><code>employments</code></td><td>One stint of work.</td><td><code>start_date</code>, <code>end_date</code> (last day), job title, contract type, hours, <code>line_manager_person_id</code>, <code>employee_ref</code> (unique per organisation), leaving reason. A rehire is a new row.</td></tr>
<tr><td><code>employment_changes</code></td><td>History of a move.</td><td>Field, from value, to value, effective date.</td></tr>
<tr><td><code>employment_details</code></td><td>Terms of an employment.</td><td>One row per employment: TUPE, working time, apprenticeship, visa, leave.</td></tr>
<tr><td><code>teams</code></td><td>Services and teams.</td><td>Parent, kind, <code>staff_only</code>, active.</td></tr>
<tr><td><code>team_assignments</code></td><td>Membership stints.</td><td><code>valid_from</code>, <code>valid_to</code>, role, primary flag, source.</td></tr>
<tr><td><code>support_episodes</code></td><td>Periods of support.</td><td>Start and end dates, reference, NHS number, local authority reference.</td></tr>
<tr><td><code>care_needs</code></td><td>Needs for a person supported.</td><td>Category, description, severity, review date, <code>ended_on</code>.</td></tr>
<tr><td><code>person_contacts</code></td><td>Contacts.</td><td>Type, label, value, primary.</td></tr>
<tr><td><code>key_worker_assignments</code></td><td>Key worker links.</td><td>Supported person, staff person, role label, <code>valid_from</code>, <code>valid_to</code>.</td></tr>
<tr><td><code>registrations</code>, <code>supervisions</code>, <code>appraisals</code>, <code>learning_records</code></td><td>Compliance records.</td><td>Belong to the person.</td></tr>
<tr><td><code>profile_changes</code></td><td>Self-service requests.</td><td>Field, label, current and proposed value (encrypted for sensitive fields), status (pending, approved, rejected, withdrawn), reviewer, reason.</td></tr>
<tr><td><code>users</code></td><td>Logins.</td><td>Unique email, password hash (null for Microsoft-only), role, status, <code>access_ends_on</code>, invitation token hash, <code>oidc_subject</code>.</td></tr>
<tr><td><code>checklist_templates</code>, <code>checklist_template_items</code></td><td>What creates tasks.</td><td>Per kind (joiner, mover, leaver).</td></tr>
<tr><td><code>tasks</code></td><td>Work to do.</td><td>Kind, title, due date, owner role, status (open, done, skipped).</td></tr>
<tr><td><code>outbox_events</code></td><td>Events.</td><td>The signed-and-sent payload, <code>fanned_out_at</code>.</td></tr>
<tr><td><code>webhook_endpoints</code>, <code>webhook_deliveries</code></td><td>Webhooks.</td><td>Address, secret, event filter, active; per-delivery attempts, status and last error.</td></tr>
<tr><td><code>audit_log</code></td><td>Who did what.</td><td>Append-only.</td></tr>
<tr><td><code>scim_tokens</code></td><td>SCIM credentials.</td><td>Only a SHA-256 hash of each token.</td></tr>
<tr><td><code>login_attempts</code></td><td>Failed sign-ins.</td><td>For the lock-out rule.</td></tr>
<tr><td><code>schema_migrations</code></td><td>Which upgrades have run.</td><td>See <a href="/docs/operations">Day-to-day operations</a>.</td></tr>
</tbody></table></div>

<h2>The period rule</h2>
<p>For <code>employments</code>, <code>support_episodes</code>, <code>team_assignments</code> and <code>key_worker_assignments</code>, a row is <strong>active on date D</strong> when <code>start &lt;= D AND (end IS NULL OR end &gt;= D)</code>. The end date is the last day, inclusive. All queries use this one rule.</p>

<h2>Deletion</h2>
<p>Lifecycle processes never delete rows. They end them. Rows are removed only by a few explicit actions: removing a compliance record, removing a contact, removing a checklist item, and (by foreign key) deleting an entire organisation or person in the database.</p>

<h2>Upgrading the schema</h2>
<p>Numbered files in <code>database/migrations/</code> are applied once each, in order, by <code>php scripts/migrate.php</code>. A new installation builds from <code>schema.sql</code>, which already contains them all. See <a href="/docs/operations">operations</a>.</p>
