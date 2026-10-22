<p>Tasks are the follow-up work that <a href="/docs/joiners">joiners</a>, <a href="/docs/movers">movers</a> and <a href="/docs/leavers">leavers</a> create. They are how the things a computer cannot do for you (a right-to-work check, an exit conversation, returning a key fob) do not get forgotten.</p>

<h2>The Tasks page</h2>
<p>Lists open tasks, oldest due first, each showing the task, who it is about (click for their profile), its due date with an <strong>Overdue</strong> marker, and its kind. Managers and administrators see every task. Staff see only tasks owned by the staff role.</p>
<h3>Completing a task</h3>
<p>Choose <strong>Done</strong>, or <strong>Skip</strong> if it does not apply. Care Core records who did it and when, writes an activity log entry and, for done, a <code>task.completed</code> event.</p>

<h2>Where tasks come from</h2>
<div class="tablewrap"><table>
<thead><tr><th>Kind</th><th>Created by</th></tr></thead>
<tbody>
<tr><td><strong>joiner</strong></td><td>Starting someone, from the <em>New starter</em> checklist.</td></tr>
<tr><td><strong>mover</strong></td><td>A change of role or team, from the <em>Change of role or team</em> checklist.</td></tr>
<tr><td><strong>leaver</strong></td><td>Recording leaving, from the <em>Leaver</em> checklist.</td></tr>
<tr><td><strong>reassign</strong></td><td>Created by the leaver process: find a new key worker for each person supported, and a new line manager for each person who reported to them.</td></tr>
<tr><td><strong>manual</strong></td><td>Reserved for tasks added by hand.</td></tr>
</tbody></table></div>

<h2>Due dates and owners</h2>
<p>Each checklist item has a <strong>due offset</strong>, in days relative to the key date (the start date for a joiner, the effective date for a mover, the last day for a leaver), and an <strong>owner role</strong> (administrator, manager or staff). Negative offsets are due <em>before</em> the date: a right-to-work check three days before someone starts, an exit conversation a week before they leave.</p>

<h2>The default checklists</h2>
<h3>New starter</h3>
<ol><li>Check right to work and identity documents (admin, 3 days before)</li><li>Complete PVG or disclosure check (admin, 3 days before)</li><li>Confirm professional registration, for example SSSC (manager, on the day)</li><li>Issue a login and equipment (admin, on the day)</li><li>Induction and shadow shifts planned (manager, on the day)</li><li>Mandatory training booked (manager, +7 days)</li><li>First supervision booked (manager, +28 days)</li></ol>
<h3>Change of role or team</h3>
<ol><li>Update rota and permissions (manager, on the day)</li><li>Confirm training for the new role (manager, +14 days)</li></ol>
<h3>Leaver</h3>
<ol><li>Exit conversation (manager, 7 days before)</li><li>Return keys, ID badge and equipment (manager, on the day)</li><li>Remove system access (admin, on the day)</li><li>Final pay and leave balance sent to payroll (admin, on the day)</li><li>Handover of people they support (manager, 3 days before)</li></ol>
<p>Administrators can change all of these: see <a href="/docs/admin-checklists">Checklists</a>. Changes apply to tasks created from then on.</p>
<p>Next: <a href="/docs/admin-users">Logins</a>.</p>
