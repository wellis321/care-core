<p>Care Core keeps data consistent by design: every lifecycle change is one transaction. The <strong>consistency check</strong> is the safety net that <em>proves</em> it. It looks for anything that still disagrees, for example because someone changed the database directly, or an import went wrong.</p>
<p>Managers and administrators can open <strong>Admin › Consistency checks</strong>. Only administrators can fix what it finds.</p>

<h2>What it checks</h2>
<div class="tablewrap"><table>
<thead><tr><th>Finding</th><th>Severity</th><th>Meaning</th><th>Fixable</th></tr></thead>
<tbody>
<tr><td>Team place for a leaver</td><td>High</td><td>Someone is still in a team although they no longer work or receive support here.</td><td>Yes</td></tr>
<tr><td>Login for a leaver</td><td>High</td><td>A login still works for someone who left.</td><td>Yes</td></tr>
<tr><td>Key worker has left</td><td>High</td><td>A person’s key worker is no longer current staff.</td><td>Yes</td></tr>
<tr><td>Key worker, no longer supported</td><td>Medium</td><td>A key worker link remains for someone who is no longer receiving support.</td><td>Yes</td></tr>
<tr><td>No key worker</td><td>Medium</td><td>A person we support has no key worker.</td><td>No: a person must choose one.</td></tr>
<tr><td>Staff in no team</td><td>Low</td><td>A current member of staff is not in any team.</td><td>No: a person must choose a team.</td></tr>
</tbody></table></div>

<h2>Fixing</h2>
<p><strong>Put right what can be put right</strong> ends the offending team places, logins and key worker links, always on the person’s own <strong>last active day</strong> (not on today), so the history says what should have been true. Each fix is written to the activity log. Findings that need a decision (no key worker, no team) are only reported.</p>

<h2>Running it automatically</h2>
<p>Schedule the script so you hear about problems without looking:</p>
<pre><code>15 3 * * *  php /path/to/scripts/reconcile.php          # report only
15 3 * * *  php /path/to/scripts/reconcile.php --fix    # report and fix</code></pre>
<p>High-severity findings also appear on the <a href="/docs/dashboard">dashboard</a> under “Needs attention”.</p>
<p>In a healthy installation using only Care Core, this list is empty (apart from the low-severity “no key worker” and “no team” reminders).</p>
