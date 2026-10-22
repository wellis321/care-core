<p>The Care module holds the information about <strong>people we support</strong>. It is available to managers and administrators when the Care module is on. Staff cannot see it.</p>
<div class="note"><strong>Sensitive information.</strong> This module is for the operational facts needed to run the service. It is not a care-planning or clinical record, and it does not replace one. See <a href="/docs/limits">What it does not do yet</a>.</div>

<h2>Adding a person we support</h2>
<p>From People choose <strong>Add a person we support</strong> and enter first name, last name (required), known as, date of birth, <strong>support starts on</strong> (required), your own <strong>reference</strong>, <strong>NHS number</strong> and the <strong>local authority reference</strong>. The NHS number is checked: 10 digits and a correct check digit (spaces and dashes are allowed as you type). This creates the person and their first <strong>support episode</strong>.</p>
<p>If the person already exists (for example a former member of staff), start a support episode from their profile rather than creating them again.</p>

<h2>Support episodes</h2>
<p>An episode runs from the first day of support to the last day. A person can have several over time (a return after a break) but only one at once (“This person is already receiving support”). A new episode cannot start before the previous one ended.</p>
<h3>Ending support</h3>
<p>Choose <strong>End support</strong> and give the last day (not before it started). Care Core also ends, on that day, <strong>their key worker links and their team places</strong>, so nobody remains linked to someone who is no longer supported. The profile keeps the whole history.</p>

<h2>Care needs</h2>
<p>A list of needs: a <strong>category</strong> (for example Falls risk, Medication), a <strong>description</strong>, an optional <strong>severity</strong> (low, medium, high) and an optional <strong>review date</strong>. A need can be <strong>ended</strong> when it no longer applies, and stays in the history.</p>

<h2>Contacts</h2>
<p>People connected to the person we support. Types: phone, email, address, next of kin, legal guardian, social worker, GP, other. Each has a label and a value, and one can be marked <strong>primary</strong>. Contacts can be removed.</p>

<h2>Key workers</h2>
<p>A key worker link connects a <strong>member of current staff</strong> to a <strong>person currently receiving support</strong>, with a role label (default “Key worker”) and a start date. Rules:</p>
<ul>
<li>Only <strong>current staff</strong> can be key workers.</li>
<li>The person must be <strong>receiving support</strong>.</li>
<li>Nobody can be their own key worker.</li>
<li>The same member of staff cannot be key worker for the same person twice at once.</li>
<li>The link cannot start before support started.</li>
<li>A link can be <strong>ended</strong> on a date; it cannot end before it started.</li>
</ul>
<p>When a key worker leaves, their links end automatically and a task is created to find each person a new key worker. When someone’s support ends, their key worker links end too. On a staff member’s profile the people they are key worker for are listed.</p>

<h2>Events</h2>
<p><code>support.started</code>, <code>support.ended</code>, <code>keyworker.started</code>, <code>keyworker.ended</code>. Events carry identifiers and dates only: never names, needs or health information.</p>
<p>Next: <a href="/docs/tasks">Tasks and checklists</a>.</p>
