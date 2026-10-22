<p>Teams model your services and how you are organised: a care home with a day team and a night team, a care-at-home service with several areas, an HR team, senior leadership. The <strong>Teams</strong> page is available when the Teams module is on.</p>

<h2>The structure</h2>
<ul>
<li>A team can sit <strong>under another team</strong> (a “parent”), to any depth. The Teams page shows the tree.</li>
<li>A team cannot sit under itself, or under one of its own sub-teams. Care Core refuses loops.</li>
<li>Each team has a <strong>kind</strong> (free text: care, support, HR, management…) which you can use to group things, and a <strong>staff only</strong> option.</li>
<li><strong>Staff-only</strong> teams refuse people we support, so a management or HR team can never accidentally contain a service user.</li>
</ul>

<h2>Creating and editing</h2>
<p>Managers and administrators create teams from the Teams page: name (required), parent team, kind and staff-only. Open a team to edit those, to see its sub-teams, and to manage its members.</p>

<h2>Members and stints</h2>
<p>Each person’s time in a team is a <strong>stint</strong> with a start date and (when they leave) an end date. Leaving and rejoining makes a <em>new</em> stint, never overwriting the old one.</p>
<h3>Adding a member</h3>
<p>Choose the person, their <strong>role in the team</strong> (for example “Team leader”), the start date, and whether this is their <strong>primary team</strong>.</p>
<h3>Ending a member’s place</h3>
<p>Choose <strong>End</strong> against their name and give the last day.</p>

<h2>Rules</h2>
<ul>
<li>A person has at most <strong>one active place per team</strong>. Adding someone who is already in the team just refreshes their role.</li>
<li>A staff member has <strong>one primary team</strong>. Making another primary ends the primary flag on the first.</li>
<li>A new stint <strong>cannot start before the previous one ended</strong> (“They left this team on 3 Mar 2026, so the new start date cannot be earlier”).</li>
<li>The last day cannot be before the stint started.</li>
<li>A <strong>closed</strong> team cannot take new members.</li>
<li>A team with active <strong>sub-teams</strong> cannot be closed until those are moved or closed.</li>
</ul>

<h2>Closing a team</h2>
<p>Closing a team ends all its current stints on the closing day and marks it closed. The team and its history remain, and it can be seen in the people’s histories.</p>

<h2>When people join, move and leave</h2>
<p>You rarely edit team membership by hand. The <a href="/docs/joiners">joiner</a>, <a href="/docs/movers">mover</a> and <a href="/docs/leavers">leaver</a> processes maintain it for you, so a leaver is always taken out of their teams on their last day.</p>
<p>Events: <code>team.created</code>, <code>team.updated</code>, <code>team.closed</code>, <code>team_assignment.started</code>, <code>team_assignment.ended</code>.</p>
<p>Next: <a href="/docs/care">People we support</a>.</p>
