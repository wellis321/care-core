<p>Care Core can bring in people, staff, teams and everything attached to them from other systems: the older Staff, People and Team services, a spreadsheet you have turned into JSON, or any system that can produce a file in the <a href="/docs/import-format">Care Core import format</a>. You always see exactly what <em>would</em> happen before anything changes.</p>
<p>Moving from the three older apps? Go straight to the <a href="/docs/import-migrating">migration guide</a>.</p>

<h2>How it works</h2>
<ol>
<li><strong>Produce import files.</strong> One JSON file per source, in the import format. The <a href="/docs/import-migrating">exporters</a> make them from the older systems.</li>
<li><strong>Check.</strong> Upload them under <strong>Admin › Import data</strong> (or run <code>scripts/import.php</code> without <code>--commit</code>). Care Core does <em>all the work</em> inside a transaction and then throws it away. The report is what a real import would do.</li>
<li><strong>Read the report.</strong> Counts per kind of record, <em>warnings</em> (a value was left out or adjusted) and <em>errors</em> (a record cannot be imported).</li>
<li><strong>Import.</strong> When you are happy, choose <strong>Import for real</strong>. By default it is <strong>all or nothing</strong>: if any record cannot be imported, nothing is. Or tick “import the records that are fine” to leave the bad ones out.</li>
</ol>

<h2>What is imported</h2>
<div class="tablewrap"><table>
<thead><tr><th>Kind</th><th>Becomes</th></tr></thead>
<tbody>
<tr><td>Teams</td><td>Teams, with their hierarchy, kind and staff-only setting. A team marked inactive is closed after its history is in.</td></tr>
<tr><td>People</td><td>People: names, date of birth, email, phone, notes.</td></tr>
<tr><td>Profile</td><td>Address, emergency contact, National Insurance and bank details (encrypted), for staff or people we support.</td></tr>
<tr><td>Employments</td><td>Employments with start and end dates, contract, hours, employee reference and line manager; their terms (TUPE, working time, apprenticeship, visa, leave, job terms); and the history of role changes.</td></tr>
<tr><td>Support episodes</td><td>Periods of support with reference, NHS number and local authority reference.</td></tr>
<tr><td>Care needs, contacts</td><td>The same as in the Care module.</td></tr>
<tr><td>Registrations, supervisions, appraisals, learning</td><td>The compliance records.</td></tr>
<tr><td>Team places</td><td>Team membership stints, current and past, with role and primary flag.</td></tr>
<tr><td>Key workers</td><td>Key worker links, current and past.</td></tr>
<tr><td>Photos and signatures</td><td>Only with the command-line importer and the old system’s uploads folder (<code>--files-from</code>).</td></tr>
</tbody></table></div>

<h2>What is not imported</h2>
<ul>
<li><strong>Logins, passwords and roles.</strong> Passwords cannot and should not be copied. People sign in with Microsoft, or you create their logins afterwards (<a href="/docs/admin-users">Logins</a>, or Entra <a href="/docs/scim">provisioning</a>).</li>
<li><strong>Tasks and checklists.</strong> Nobody needs onboarding tasks for a member of staff who started in 2019.</li>
<li><strong>The old systems’ activity logs.</strong> The import adds its own entries (see below).</li>
<li><strong>Documents and certificates</strong> (Care Core does not store them yet), job descriptions and job posts, salary history, leave bookings.</li>
<li><strong>Settings:</strong> API keys, webhooks, Entra settings, organisational units.</li>
</ul>

<h2>The rules it follows</h2>
<h3>It goes through the same services as typed data</h3>
<p>Values are checked exactly as they would be in the web interface: postcodes, National Insurance numbers, sort codes, NHS numbers, dates, team rules (staff-only teams, one primary team, no overlapping stints), key worker rules. A value that is not acceptable is <strong>left out with a warning</strong> rather than allowed to break the record. A person with no name is an <strong>error</strong>: that record is skipped.</p>
<h3>Matching people who are already there</h3>
<p>If a person in the file is already in Care Core, they are <strong>matched, not duplicated</strong>: first by an earlier import of the same source reference, then by email address (not case sensitive), then by first name, last name and date of birth when exactly one person fits. Matched people are <strong>filled in, never overwritten</strong>: blank fields get the imported value; existing values stay. Their employments, compliance records and so on are added unless they would overlap or duplicate what is there.</p>
<h3>History stays history</h3>
<ul>
<li>Ended employments, support and team places are imported with their dates.</li>
<li>If the old system never closed a team place or key worker link for someone who has left (or whose support ended), it is <strong>ended on their last day</strong>, with a warning. Nothing outlives the people in it. After an import, the <a href="/docs/admin-reconcile">consistency check</a> should show no high-severity findings.</li>
<li>No onboarding tasks are created, and <strong>no webhook events are sent</strong> for imported history, so a migration does not flood other systems with old news. The activity log records each imported person (as <code>system:import</code>), plus the entries Care Core’s services make as they go.</li>
</ul>
<h3>Running it again is safe</h3>
<p>Every record keeps a <em>source reference</em> (such as <code>staff:12</code>). Care Core remembers which reference became which record, so importing the same files again adds nothing that is already there. New people in the source are picked up. <strong>Changes to people already imported are not copied across</strong>: after the first import, Care Core is the system of record.</p>

<h2>On the command line</h2>
<pre><code>php scripts/import.php --org=your-org staff.json people.json teams.json              # check only
php scripts/import.php --org=your-org --commit staff.json people.json teams.json     # do it</code></pre>
<div class="tablewrap"><table>
<thead><tr><th>Option</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><code>--org=</code></td><td>The organisation’s slug or id (required).</td></tr>
<tr><td><code>--commit</code></td><td>Really import. Without it, everything is checked and rolled back.</td></tr>
<tr><td><code>--skip-errors</code></td><td>Import the records that are fine even if some cannot be.</td></tr>
<tr><td><code>--files-from=DIR</code></td><td>The old system’s uploads folder, to bring photos and signatures across. Files are only read from inside that folder, and must be genuine images.</td></tr>
<tr><td><code>--json</code></td><td>Print the report as JSON.</td></tr>
</tbody></table></div>
<p>Use the command line for very large imports: the web upload is limited by your server’s upload size, and at most six files at a time. Exit status is 0 for success, 2 when there were errors.</p>
<p>Every check and import is listed on <strong>Admin › Import data</strong>.</p>

<h2>Before you import for real</h2>
<ul>
<li><strong>Back up the database</strong> (and <code>storage/</code>). An import cannot be undone from the interface.</li>
<li>Practise on a copy: restore a backup to a test database and import there first.</li>
<li>Read the warnings. They are the list of data quality problems in the old system.</li>
<li>Decide which system is the source of truth during the changeover, so nobody edits the old and new at once.</li>
</ul>
<p>Next: <a href="/docs/import-format">The import format</a>, or <a href="/docs/import-migrating">moving from the older Staff, People and Team services</a>.</p>
