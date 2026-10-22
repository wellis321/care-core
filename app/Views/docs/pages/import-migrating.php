<p>This is the runbook for moving everything from the older <strong>Staff Service</strong>, <strong>People Service</strong> and <strong>Team Service</strong> into Care Core. The three exporters read the old databases (read only) and write import files; Care Core then checks and imports them. Nothing in the old systems is changed.</p>

<h2>What you need</h2>
<ul>
<li>Shell access to a machine that can reach the old databases and has this Care Core code (your laptop or the server).</li>
<li>Database credentials that can <em>read</em> each old database. A read-only database user is ideal.</li>
<li>The <strong>organisation id</strong> in each old system (the <code>id</code> in its <code>organisations</code> table).</li>
<li>The new Care Core installed, with its organisation created (<code>php scripts/create-organisation.php</code>), and a backup.</li>
</ul>

<h2>Step 1: export each system</h2>
<pre><code>php scripts/export-old-systems.php staff  --db=people_management --org=1 --user=reader --pass=… &gt; staff.json
php scripts/export-old-systems.php people --db=people_service    --org=1 --user=reader --pass=… &gt; people.json
php scripts/export-old-systems.php teams  --db=team_service      --org=1 --user=reader --pass=… &gt; teams.json</code></pre>
<p>Add <code>--host=</code> and <code>--port=</code> if the databases are elsewhere (or set <code>OLD_DB_USER</code> and <code>OLD_DB_PASS</code> in the environment so the password is not on the command line). Each export prints a summary and any <strong>notes</strong> (assumptions it had to make) to the screen, and the JSON to the file.</p>

<h2>Step 2: check</h2>
<pre><code>php scripts/import.php --org=your-org staff.json people.json teams.json</code></pre>
<p>Or upload the three files under <strong>Admin › Import data</strong>. Read the report. A typical first run shows warnings for things like postcodes that are not valid, phone numbers with words in them, and key worker links that were never closed. Those are the old data’s problems, now listed in one place. Fix what matters in the source (or in Care Core afterwards) and check again. Because a check changes nothing, repeat as often as you like.</p>

<h2>Step 3: import</h2>
<pre><code>php scripts/import.php --org=your-org --commit --files-from=/path/to/old/uploads staff.json people.json teams.json</code></pre>
<p>Drop <code>--files-from</code> if you do not need photos and signatures. Add <code>--skip-errors</code> to import the records that are fine when a few cannot be. Or use <strong>Import for real</strong> on the report page.</p>

<h2>Step 4: verify</h2>
<ol>
<li><strong>Counts:</strong> People, Teams and the staff list should match the old systems (allowing for people matched or merged).</li>
<li><strong>Consistency checks</strong> (Admin): there should be no high-severity findings. “No key worker” reminders are normal.</li>
<li><strong>Spot-check</strong> a handful of people you know well: employment dates, line manager, team history, registrations, key workers.</li>
<li><strong>Due soon:</strong> the compliance list should look right for current staff.</li>
</ol>

<h2>Step 5: logins and sign-in</h2>
<p>Logins are not imported. Either <a href="/docs/entra-signin">set up Microsoft sign-in</a> and <a href="/docs/scim">Entra provisioning</a> (best: staff sign in with their work account), or create logins on <a href="/docs/admin-users">Admin › Logins</a> and hand out invitations.</p>

<h2>Step 6: changeover and retiring the old systems</h2>
<ol>
<li>Pick a cut-over time. Stop editing the old systems (set them read-only, or tell everyone).</li>
<li>Run the exports and the import one final time. Re-running is safe: people already imported are skipped and anything new is added.</li>
<li>Check, then point people and other systems at Care Core. Connect other systems through the <a href="/docs/api">API</a> and <a href="/docs/webhooks">webhooks</a>.</li>
<li>Keep the old systems’ databases, <strong>archived and read-only</strong>, for as long as your retention policy requires.</li>
<li>After the cut-over, Care Core is the only system of record. Changes made in the old systems are not carried over.</li>
</ol>

<h2>What each old system contributes</h2>
<h3>Staff Service (people_management)</h3>
<div class="tablewrap"><table>
<thead><tr><th>Old data</th><th>Becomes</th></tr></thead>
<tbody>
<tr><td><code>people</code> where <code>person_type = staff</code></td><td>A person, ref <code>staff:&lt;id&gt;</code>. Name, email, phone, date of birth. Photo from <code>photo_path</code> (approved photos only).</td></tr>
<tr><td><code>people.employee_reference</code></td><td>The employment’s employee reference.</td></tr>
<tr><td><code>staff_profiles</code></td><td>The employment (job title, start and end dates, hours, contract type, line manager) and its terms, plus the personal profile (address, emergency contact, National Insurance, bank, signature).</td></tr>
<tr><td>TUPE, working time, apprenticeship, visa, leave fields</td><td>The matching sections of the staff record.</td></tr>
<tr><td><code>staff_registrations</code>, <code>staff_supervisions</code>, <code>staff_appraisals</code>, <code>staff_learning_records</code></td><td>The compliance records. Supervisor and appraiser point at the right person.</td></tr>
<tr><td><code>staff_role_history</code></td><td>The employment’s history of changes (job title, hours, contract type).</td></tr>
<tr><td><code>people</code> where <code>person_type = person_we_support</code></td><td>Not exported (a note says how many). Import people we support from the People Service.</td></tr>
</tbody></table></div>
<p><strong>Assumptions the exporter makes</strong> (each is reported as a note):</p>
<ul>
<li>No employment start date → the date the record was created.</li>
<li>Marked inactive but no end date → the date the record was last changed is used as the last day. <strong>Check these.</strong></li>
</ul>

<h3>People Service (people_service)</h3>
<div class="tablewrap"><table>
<thead><tr><th>Old data</th><th>Becomes</th></tr></thead>
<tbody>
<tr><td><code>people</code></td><td>A person, ref <code>people:&lt;id&gt;</code>, with a support episode from <code>support_start</code> to <code>support_end</code>, NHS number and local authority reference. Address goes to the personal profile.</td></tr>
<tr><td><code>gender</code>, <code>pronouns</code></td><td>Care Core has no fields for these, so they are added to the person’s <strong>notes</strong> (a note says how many).</td></tr>
<tr><td><code>person_contacts</code>, <code>care_needs</code></td><td>Contacts and care needs.</td></tr>
<tr><td><code>person_keyworkers</code></td><td>Key worker links to <code>staff:&lt;staff_id&gt;</code>: the Staff Service’s ids.</td></tr>
</tbody></table></div>
<p>Assumptions: no support start → the creation date; <code>inactive</code> or <code>archived</code> with no end → the last-changed date.</p>

<h3>Team Service (team_service)</h3>
<div class="tablewrap"><table>
<thead><tr><th>Old data</th><th>Becomes</th></tr></thead>
<tbody>
<tr><td><code>teams</code></td><td>Teams with their hierarchy; inactive teams are closed after their history is in.</td></tr>
<tr><td><code>team_types</code></td><td>The team’s <em>kind</em>; a type marked staff-only makes its teams staff-only.</td></tr>
<tr><td><code>team_members</code> (staff and people)</td><td>Team places with dates, role (from <code>team_roles</code>) and primary flag. Members point at <code>staff:&lt;id&gt;</code> or <code>people:&lt;id&gt;</code>.</td></tr>
<tr><td><code>team_members</code> of type <em>user</em></td><td>Not exported: logins are not people.</td></tr>
</tbody></table></div>

<h2>Not carried across</h2>
<p>See <a href="/docs/import">What is not imported</a>: logins and passwords, tasks, the old activity logs, documents and certificates, job descriptions and posts, salary and leave bookings, settings, and organisational units. If the Staff Service used <em>organisational units</em> instead of the Team Service for teams, build a small teams file by hand in the <a href="/docs/import-format">import format</a>.</p>

<h2>If something goes wrong</h2>
<ul>
<li><strong>The check shows errors:</strong> read them; each names the source record. Fix it in the source or use <code>--skip-errors</code>.</li>
<li><strong>You imported and want to start again:</strong> restore the backup you took, and import again. (An import cannot be undone from inside Care Core.)</li>
<li><strong>The exporter cannot connect or says a table is missing:</strong> check <code>--db</code>, <code>--host</code>, the user’s rights, and that the old system’s database has been upgraded to its latest version.</li>
<li><strong>A person is duplicated:</strong> the old systems held them under different emails and the same name with no date of birth. Edit one record into the other by hand, or correct the source and re-run on a fresh copy.</li>
</ul>
