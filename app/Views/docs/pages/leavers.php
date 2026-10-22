<p>The <strong>leaver process</strong> is the one Care Core exists to get right. Open the person’s profile and choose <strong>Record leaving</strong>.</p>

<h2>Step 1: preview</h2>
<p>Enter the <strong>last day of work</strong> and choose <strong>Show what will happen</strong>. Care Core shows you, before anything changes:</p>
<div class="tablewrap"><table>
<thead><tr><th>Panel</th><th>What it lists</th></tr></thead>
<tbody>
<tr><td>Team places</td><td>Every team they are in, which will end on their last day.</td></tr>
<tr><td>Key worker for</td><td>Every person they are key worker for. The links end and a task is created to find each a new key worker.</td></tr>
<tr><td>People who report to them</td><td>Everyone whose line manager they are. A task is created to give each a new line manager.</td></tr>
<tr><td>Logins</td><td>The logins that will stop working after their last day.</td></tr>
<tr><td>Checklist</td><td>How many offboarding tasks will be created.</td></tr>
</tbody></table></div>
<p>This is the moment to notice, for example, that someone is the key worker for three people and you need a handover plan.</p>

<h2>Step 2: record it</h2>
<p>Choose a <strong>reason</strong> (resigned, retired, dismissed, end of contract, redundancy, moved to another service, other, or not recorded) and confirm. In one transaction Care Core:</p>
<ol>
<li>Sets the <strong>end date</strong> of their employment to the last day. If the last day is in the future, they stay employed until then and appear under “leaving soon”.</li>
<li><strong>Ends their team places</strong> on the last day. A place that had not started yet is cancelled.</li>
<li><strong>Ends their key worker links</strong> and creates a <strong>“reassign”</strong> task for each person who needs a new key worker.</li>
<li>Creates a <strong>reassign</strong> task for each person who has them as line manager.</li>
<li>Sets the login’s <strong>access ends on</strong> date to the last day. They can sign in on that day and not the day after. Nothing has to run for this to happen.</li>
<li>Creates the <strong>leaver checklist</strong> tasks (for example exit conversation, return equipment, remove system access, final pay).</li>
<li>Writes the activity log entry and the <code>employment.ended</code>, <code>team_assignment.ended</code> and <code>keyworker.ended</code> events. Events caused by the leaving carry <code>cause: employment_ended</code>.</li>
</ol>

<h2>Rules</h2>
<ul>
<li>The last day cannot be before they started.</li>
<li>An employment that has already ended cannot be ended again.</li>
</ul>

<h2>What stays</h2>
<p>Everything. The person, their employment (now with an end date), their team history, their key worker history, their registrations and training, and the activity log all remain. Former staff appear under <em>Leavers</em> in People. If they return, <a href="/docs/joiners">start a new employment</a>.</p>

<div class="note"><strong>Leaving through Microsoft Entra.</strong> When Entra disables or removes the person, Care Core runs this same process with today as the last day, and the login is disabled immediately. See <a href="/docs/scim">Provisioning from Entra</a>.</div>
<p>Next: <a href="/docs/staff-record">The staff record</a>.</p>
