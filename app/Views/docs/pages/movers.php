<p>The <strong>mover process</strong> records a change to someone’s current job: a new title, contract, hours, line manager or team. Open their profile and choose <strong>Change role or team</strong>.</p>

<h2>What you can change</h2>
<ul>
<li><strong>Job title</strong></li>
<li><strong>Contract type</strong></li>
<li><strong>Hours per week</strong></li>
<li><strong>Line manager</strong> (chosen from current staff; not themselves)</li>
<li><strong>Team</strong> and their <strong>role in the team</strong></li>
</ul>
<p>Leave a field as it is to keep it. If nothing has changed, Care Core says so and saves nothing.</p>

<h2>The effective date</h2>
<p>Every change has an <strong>effective date</strong>, defaulting to today. It can be today or earlier (for a change you are recording late). It <strong>cannot be in the future</strong>: record a future promotion on the day it takes effect. It also cannot be before the employment started.</p>

<h2>What happens</h2>
<ol>
<li>The employment is updated.</li>
<li>For each field that changed, a <strong>history row</strong> records what it was, what it became, and from when. These appear on the profile under “Changes to the current role”.</li>
<li>If the team changed, the old primary team stint <strong>ends the day before</strong> the effective date and the new one <strong>starts on</strong> it, so there is no gap and no overlap.</li>
<li>The <strong>change of role or team checklist</strong> creates its tasks (for example “Update rota and permissions”).</li>
<li>An activity log entry and an <code>employment.changed</code> event (listing which fields changed) are written.</li>
</ol>

<h2>Rules</h2>
<ul>
<li>The employment must be active on the effective date. For someone who has already left, start a new employment instead.</li>
<li>A team change cannot start before the person last left that team.</li>
<li>Someone cannot be placed in a closed team.</li>
</ul>
<p>Next: <a href="/docs/leavers">Recording someone leaving</a>.</p>
