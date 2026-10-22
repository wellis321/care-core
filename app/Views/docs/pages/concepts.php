<p>A handful of ideas explain almost everything Care Core does. Once you have them, the rest follows.</p>

<h2>A person is permanent; relationships are periods</h2>
<p>Every human being is created <strong>once</strong>, as a <em>person</em>, and is never deleted. Being a member of staff, receiving support, being in a team or being someone’s key worker are <strong>relationships</strong> that person has, and each relationship has a start date and an optional end date:</p>
<div class="tablewrap"><table>
<thead><tr><th>Relationship</th><th>Starts</th><th>Ends</th></tr></thead>
<tbody>
<tr><td>Employment</td><td>First day of work</td><td>Last day of work</td></tr>
<tr><td>Support episode</td><td>First day of support</td><td>Last day of support</td></tr>
<tr><td>Team place</td><td>The day they join the team</td><td>The day they leave it</td></tr>
<tr><td>Key worker link</td><td>The day it starts</td><td>The day it ends</td></tr>
<tr><td>Login</td><td>When it is created</td><td>The “access ends on” date (a leaver’s last day)</td></tr>
</tbody></table></div>
<p>Because relationships are rows, not flags, you never lose the past. A rehire is a second employment. Someone returning to a team is a second team place.</p>

<h2>“Active on a date” and “last day”</h2>
<p>One rule is used everywhere: something is <strong>active on a date</strong> when it started on or before that date and either has no end date or ends on or after that date. The <strong>end date is the last day</strong>, inclusive. Someone whose last day is Friday works on Friday, and is gone on Saturday.</p>

<h2>Nothing needs to “run” for a leaver to lose access</h2>
<p>When someone leaves, their login is given an <em>access ends on</em> date equal to their last day. Every request checks that date. There is no overnight job that could fail to run, so access stops on the right day by construction. (A sign-in with Microsoft is also checked against the same date.)</p>

<h2>Lifecycle actions happen in one transaction</h2>
<p>Joiner, mover and leaver are each a single operation. For example, recording someone leaving ends their employment, ends their team places, ends their key worker links (and creates a task to find each supported person a new key worker), creates a task for each person who reports to them, sets their login’s end date, creates their offboarding checklist, writes the audit record and queues the outgoing events. All of it succeeds or none of it does.</p>

<h2>Audit and events come with every change</h2>
<ul>
<li>The <strong>activity log</strong> records who did what, to whom, and when. It only ever grows.</li>
<li>The <strong>outbox</strong> records an event for every change, in the same transaction. A separate process delivers those events as signed webhooks, with retries. Events carry identifiers and dates only, never names or health information.</li>
</ul>

<h2>Organisations are separate</h2>
<p>Every record belongs to an organisation and every query is limited to the signed-in person’s organisation. One organisation cannot see another’s people, even by guessing an address. This is tested.</p>

<h2>Modules</h2>
<p>Staff, Teams and Care can be switched off per organisation. Switching one off hides its pages and menus. It does not delete anything.</p>
<p>Next: <a href="/docs/roles">Roles and permissions</a>.</p>
