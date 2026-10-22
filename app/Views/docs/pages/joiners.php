<p>The <strong>joiner process</strong> adds a new member of staff, or brings back someone who has worked for you before. Open <strong>People</strong> and choose <strong>New starter</strong> (managers and administrators; the Staff module must be on).</p>

<h2>What you enter</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Notes</th></tr></thead>
<tbody>
<tr><td>First name, last name</td><td>Required for a new person.</td></tr>
<tr><td>Email</td><td>Needed if they will have a login. Must be unique across Care Core.</td></tr>
<tr><td>Known as, phone, date of birth</td><td>Optional.</td></tr>
<tr><td>Job title</td><td>Required.</td></tr>
<tr><td>Start date</td><td>Required. May be in the future: the person appears under “starting soon” until then.</td></tr>
<tr><td>Contract type</td><td>For example permanent, fixed term, bank. Left blank it becomes <em>permanent</em>.</td></tr>
<tr><td>Hours per week</td><td>Optional; a sensible number is checked.</td></tr>
<tr><td>Employee reference</td><td>Optional, but if given it must be unique in your organisation.</td></tr>
<tr><td>Line manager</td><td>Optional; chosen from current staff. Nobody can be their own line manager.</td></tr>
<tr><td>Team and team role</td><td>Optional; they are placed in that team from their start date as their primary team.</td></tr>
<tr><td>Create a login, and the login’s role</td><td>Optional. See below.</td></tr>
</tbody></table></div>

<h2>What happens, all at once</h2>
<ol>
<li>The <strong>person</strong> is created (or, for a rehire, the existing person is found).</li>
<li>An <strong>employment</strong> is created from the start date.</li>
<li>If you chose a team, a <strong>team place</strong> starts on the start date and is their primary team.</li>
<li>If you ticked “create a login”, a login is created with a <strong>one-time invitation link</strong>, shown once on the next page. See <a href="/docs/signing-in">Signing in</a>.</li>
<li>The <strong>new starter checklist</strong> creates its tasks, each due relative to the start date (some before, some after).</li>
<li>An <strong>activity log</strong> entry and an <code>employment.started</code> <strong>event</strong> are written.</li>
</ol>
<p>If any step fails (for example the email is already used), <strong>nothing</strong> is saved.</p>

<h2>Rehires</h2>
<p>If the person has worked for you before, do not create them again. Open their profile and choose <strong>Start new employment</strong>. Care Core keeps their single person record, adds a second employment, and if they still have a login it removes its end date so they can sign in again. The events say <code>rehire: true</code>.</p>
<p>If you try to start someone whose email already exists, Care Core tells you to find them in People and start a new employment instead, so you never create a duplicate.</p>
<h3>Rules for rehires</h3>
<ul>
<li>A new employment must start <strong>after</strong> the previous one ended.</li>
<li>A person cannot have two employments that overlap (“This person is already employed on that date”).</li>
</ul>

<h2>Starting someone through Microsoft Entra</h2>
<p>With <a href="/docs/scim">SCIM provisioning</a>, assigning a person to the Care Core application in Entra runs this same process automatically.</p>
<p>Next: <a href="/docs/movers">Changing role, hours or team</a>.</p>
