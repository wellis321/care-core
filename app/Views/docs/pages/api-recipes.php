<p>Worked examples for the integrations people build most. Each uses only the calls in the <a href="/docs/api-reference">reference</a>.</p>

<h2>Keep a rota or payroll system in step with your staff</h2>
<p><strong>Scopes:</strong> <code>people.read</code>, <code>staff.read</code> (and <code>events.read</code> if you want to react to changes). Without <code>care.read</code> the key can never see people who only receive support.</p>
<ol>
<li><strong>First load:</strong> page through <code>GET /employments?status=current</code> (and <code>status=upcoming</code> for people who start soon). Each employment has the person’s id, <code>employee_ref</code>, job title, contract, hours, line manager, start and end dates. Fetch names and emails with <code>GET /people?kind=staff</code>.</li>
<li><strong>Stay in step:</strong> either
  <ul>
  <li>poll <code>GET /events?types=employment.*&amp;after=LAST_CURSOR</code> every minute and apply each event in order (<code>employment.started</code>, <code>employment.changed</code>, <code>employment.ended</code>), saving the last <code>cursor</code> you processed; or</li>
  <li>create a <a href="/docs/webhooks">webhook</a> for the same events and let Care Core call you.</li>
  </ul>
  Events say <em>what</em> changed and for whom; fetch the employment or person for the details.</li>
<li><strong>Safety net:</strong> once a night, reconcile with <code>GET /people?updated_since=YESTERDAY</code> and a fresh list of current employments. Events can be missed if your receiver was down; this catches them.</li>
<li><strong>Leavers:</strong> an <code>employment.ended</code> event carries the last day (<code>end_date</code>). Disable the person in your system <em>after</em> that day. Care Core ends their access the same way.</li>
</ol>

<h2>Start someone from a recruitment system</h2>
<p><strong>Scopes:</strong> <code>staff.write</code> (plus <code>teams.write</code> only if you add team places separately).</p>
<pre><code>POST /employments
Idempotency-Key: candidate-4821
{ "first_name": "Ada", "last_name": "Lovelace", "email": "ada@example.org",
  "job_title": "Support Worker", "start_date": "2026-12-01", "hours_per_week": 37.5,
  "employee_ref": "E4821", "team_id": "…", "line_manager_id": "…" }</code></pre>
<ul>
<li>This is the <strong>joiner process</strong>: the person, the employment, the team place, and the new starter checklist, all at once. The response includes <code>tasks_created</code>.</li>
<li>The idempotency key means a retry after a timeout cannot start the same person twice. (An existing email is refused anyway.)</li>
<li>A <strong>returner</strong>: pass <code>person_id</code> instead of the name fields. The response says <code>rehire: true</code>.</li>
<li>Then add what you know: <code>PATCH /people/{id}/profile</code> (address, emergency contact; National Insurance and bank need <code>staff.sensitive</code>), <code>POST /people/{id}/registrations</code>, <code>PATCH /employments/{id}/details/wtd</code>.</li>
<li>No login is created. Access is arranged by an administrator or by Entra provisioning.</li>
</ul>

<h2>End someone’s employment from HR</h2>
<pre><code>GET  /employments/{id}/leave-preview?last_day=2026-12-18      (changes nothing)
POST /employments/{id}/end   { "last_day": "2026-12-18", "reason": "Resigned" }</code></pre>
<p>The preview says how many team places will end, how many people lose this key worker or line manager, how many logins will stop and how many offboarding tasks will be created. The <code>end</code> call does it all in one transaction and returns the same counts. The API cannot half-end someone.</p>

<h2>A compliance dashboard</h2>
<p><strong>Scopes:</strong> <code>compliance.read</code>, <code>people.read</code>.</p>
<ul>
<li><code>GET /compliance/due?days=60</code> returns everything overdue or due in the next 60 days for current staff, with the person’s id, what it is, and the date. Look the people up with <code>GET /people/{id}</code>.</li>
<li>For one person: <code>GET /people/{id}/registrations</code>, <code>/supervisions</code>, <code>/appraisals</code>, <code>/learning</code>.</li>
<li>Training from a learning system: <code>POST /people/{id}/learning</code> with <code>title</code>, <code>record_type</code>, <code>completion_date</code>, <code>expiry_date</code>, <code>status</code> and <code>is_mandatory</code>. Use the learning system’s record id as the <code>Idempotency-Key</code>.</li>
</ul>

<h2>Who is in this team, now or on any date?</h2>
<pre><code>GET /teams                               the structure
GET /teams/{id}/members                  today
GET /teams/{id}/members?on=2026-03-14    on a past date</code></pre>
<p>Each place has a <code>role</code>, <code>primary</code>, <code>start_date</code> and <code>end_date</code>. <code>GET /people/{id}/teams</code> gives one person’s whole history. To move someone, use the <a href="/docs/api-reference">mover</a> endpoint (<code>POST /employments/{id}/changes</code> with <code>team_id</code>), which ends the old primary team the day before and starts the new one, rather than editing places by hand.</p>

<h2>A care system that needs key workers and teams</h2>
<p><strong>Scopes:</strong> <code>care.read</code>, <code>teams.read</code> (and <code>care.write</code> to change).</p>
<ul>
<li><code>GET /people?kind=supported</code>: everyone currently receiving support.</li>
<li><code>GET /people/{id}/key-workers</code>: current key workers first. Called on a member of staff it lists the people they are key worker for.</li>
<li><code>POST /key-workers</code> to assign (the rules about current staff and people currently supported apply). When a key worker leaves, Care Core ends their links and creates a task for each person; read those with <code>GET /tasks?kind=reassign</code>.</li>
<li>Listen for <code>keyworker.started</code> and <code>keyworker.ended</code> events.</li>
</ul>

<h2>Close the loop on tasks</h2>
<p>Another system (a service desk, a rota) may do the work a task describes. <code>GET /tasks?status=open&amp;kind=leaver</code> lists what is waiting; <code>PATCH /tasks/{id}</code> with <code>{"status":"done"}</code> marks it complete and raises <code>task.completed</code>.</p>

<h2>Audit what an integration did</h2>
<p>Everything a key does is attributed to it. Read it back with <code>GET /audit?action=employment</code> (or filter by <code>subject_id</code>); entries show the actor <code>system:api:KEYNAME</code>. Sensitive values are never in the log.</p>

<h2>Manage webhooks as part of your own deployment</h2>
<pre><code>POST /webhooks { "url": "https://hooks.example.org/care", "event_types": ["employment.*", "team_assignment.*"] }
→ { "data": { "id": "…", "secret": "d4f6…" } }      (the secret is shown once)
GET  /webhooks/{id}/deliveries?status=failed             see what is not getting through</code></pre>
<p>See <a href="/docs/webhooks">Events and webhooks</a> for how to verify the signature.</p>

<h2>Feed events or poll?</h2>
<div class="tablewrap"><table>
<thead><tr><th>Compared</th><th>Webhooks</th><th>Event feed (<code>GET /events</code>)</th></tr></thead>
<tbody>
<tr><td>Latency</td><td>About a minute</td><td>As often as you poll</td></tr>
<tr><td>Needs a public address</td><td>Yes (https)</td><td>No: you call us</td></tr>
<tr><td>If you are down</td><td>Retried for about 15 hours, then given up</td><td>Nothing is lost: resume from your last cursor</td></tr>
<tr><td>Best for</td><td>Reacting quickly</td><td>Systems behind a firewall; guaranteed catch-up</td></tr>
</tbody></table></div>
<p>Many integrations use both: webhooks for speed and the feed to catch up.</p>
