<p>Every event has the same envelope (see <a href="/docs/webhooks">Events and webhooks</a>): <code>id</code>, <code>type</code>, <code>version</code>, <code>occurred_at</code>, <code>organisation</code>, <code>subject</code> and <code>data</code>. This page lists each <code>type</code>, who the <code>subject</code> is, and what is in <code>data</code>. All ids are UUIDs; all dates are <code>YYYY-MM-DD</code>.</p>

<h2>People</h2>
<div class="tablewrap"><table>
<thead><tr><th>Type</th><th>Subject</th><th>data</th><th>Raised when</th></tr></thead>
<tbody>
<tr><td><code>person.created</code></td><td>the person</td><td>(empty)</td><td>A person is created.</td></tr>
<tr><td><code>person.updated</code></td><td>the person</td><td><code>fields</code>: names of the fields changed (for example <code>["address_line1","phone"]</code>). Absent when basic details were edited.</td><td>Basic details edited; staff record changed; a self-service change approved or applied; a photo or signature changed.</td></tr>
<tr><td><code>user.created</code></td><td>the person (or none)</td><td><code>user</code>: the login’s UUID</td><td>A login is created.</td></tr>
</tbody></table></div>

<h2>Employment</h2>
<div class="tablewrap"><table>
<thead><tr><th>Type</th><th>Subject</th><th>data</th><th>Raised when</th></tr></thead>
<tbody>
<tr><td><code>employment.started</code></td><td>the person</td><td><code>employment</code>, <code>start_date</code>, <code>rehire</code> (true or false)</td><td>The joiner process, including SCIM.</td></tr>
<tr><td><code>employment.changed</code></td><td>the person (mover) or the employment (terms)</td><td>Mover: <code>employment</code>, <code>effective_date</code>, <code>fields</code> (which of the job title, contract type, hours, line manager or team changed, for example <code>["job_title","team"]</code>). Terms: <code>section</code> (terms, tupe, wtd, apprentice, visa or leave).</td><td>A change of role or team; a section of employment terms saved.</td></tr>
<tr><td><code>employment.ended</code></td><td>the person</td><td><code>employment</code>, <code>end_date</code> (the last day), <code>reason</code> (or null)</td><td>The leaver process, including SCIM.</td></tr>
</tbody></table></div>

<h2>Teams</h2>
<div class="tablewrap"><table>
<thead><tr><th>Type</th><th>Subject</th><th>data</th><th>Raised when</th></tr></thead>
<tbody>
<tr><td><code>team.created</code>, <code>team.updated</code>, <code>team.closed</code></td><td>the team</td><td>(empty)</td><td>A team is created, edited or closed.</td></tr>
<tr><td><code>team_assignment.started</code></td><td>the person</td><td><code>team</code>, <code>valid_from</code></td><td>Someone joins a team.</td></tr>
<tr><td><code>team_assignment.ended</code></td><td>the person</td><td><code>team</code>, <code>valid_to</code>, and <code>cause</code>: <code>employment_ended</code> when it was caused by someone leaving</td><td>Someone leaves a team, a team closes, or an employment ends.</td></tr>
</tbody></table></div>

<h2>Care</h2>
<div class="tablewrap"><table>
<thead><tr><th>Type</th><th>Subject</th><th>data</th><th>Raised when</th></tr></thead>
<tbody>
<tr><td><code>support.started</code></td><td>the person supported</td><td><code>start_date</code></td><td>A support episode begins.</td></tr>
<tr><td><code>support.ended</code></td><td>the person supported</td><td><code>end_date</code></td><td>Support ends.</td></tr>
<tr><td><code>keyworker.started</code></td><td>the person supported</td><td><code>staff</code> (the key worker’s UUID), <code>valid_from</code></td><td>A key worker is assigned.</td></tr>
<tr><td><code>keyworker.ended</code></td><td>the person supported</td><td><code>staff</code>, <code>valid_to</code>, and <code>cause</code>: <code>employment_ended</code> when the key worker left</td><td>A key worker link ends.</td></tr>
</tbody></table></div>
<p>Care needs and contacts do not raise events, to keep health-related detail out of the event stream.</p>

<h2>Compliance and tasks</h2>
<div class="tablewrap"><table>
<thead><tr><th>Type</th><th>Subject</th><th>data</th><th>Raised when</th></tr></thead>
<tbody>
<tr><td><code>registration.recorded</code></td><td>the person</td><td><code>record</code> (UUID), <code>date</code> (the expiry date)</td><td>A registration is added.</td></tr>
<tr><td><code>supervision.recorded</code></td><td>the person</td><td><code>record</code>, <code>date</code> (the next due date, if any)</td><td>A supervision is added.</td></tr>
<tr><td><code>appraisal.recorded</code></td><td>the person</td><td><code>record</code>, <code>date</code> (the next due date, if any)</td><td>An appraisal is added.</td></tr>
<tr><td><code>learning.recorded</code></td><td>the person</td><td><code>record</code>, <code>date</code> (the expiry date, if any)</td><td>A learning record is added.</td></tr>
<tr><td><code>task.completed</code></td><td>the person the task is about</td><td><code>task</code> (UUID), <code>kind</code> (joiner, mover, leaver, reassign or manual)</td><td>A task is marked done.</td></tr>
</tbody></table></div>

<h2>Examples</h2>
<h3>A leaver</h3>
<p>Recording someone’s last day as 12 November 2026 raises, in one transaction: <code>employment.ended</code>; one <code>team_assignment.ended</code> for each team (with <code>cause: employment_ended</code>); and one <code>keyworker.ended</code> for each person they were key worker for. A receiver that listens to <code>employment.ended</code> alone can disable the person in another system.</p>
<h3>A mover</h3>
<p>Changing a job title and team raises one <code>employment.changed</code> with <code>fields: ["job_title","team"]</code>, plus <code>team_assignment.ended</code> and <code>team_assignment.started</code> for the old and new teams.</p>

<h2>Compatibility</h2>
<p>The envelope is versioned. Within version 1 Care Core may <em>add</em> event types and <em>add</em> fields to <code>data</code>, but will not rename or remove existing ones. Write receivers that ignore what they do not recognise.</p>
