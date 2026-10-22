<h2>Scripts you may need</h2>
<div class="tablewrap"><table>
<thead><tr><th>Script</th><th>Does</th></tr></thead>
<tbody>
<tr><td><code>scripts/migrate.php</code></td><td>Creates the tables, or applies new upgrades to an existing database.</td></tr>
<tr><td><code>scripts/make-key.php</code></td><td>Adds <code>APP_KEY</code> to <code>.env</code> once.</td></tr>
<tr><td><code>scripts/create-organisation.php "Name" email</code></td><td>Creates an organisation and its first administrator (prints an invitation link).</td></tr>
<tr><td><code>scripts/reinvite.php email</code></td><td>Prints a new one-time invitation link for a login: for a locked-out administrator.</td></tr>
<tr><td><code>scripts/export-old-systems.php</code></td><td>Reads an older Staff, People or Team service database (read only) and writes an import file.</td></tr>
<tr><td><code>scripts/import.php</code></td><td>Checks (or, with <code>--commit</code>, performs) an import. See <a href="/docs/import">Importing data</a>.</td></tr>
<tr><td><code>scripts/deliver-events.php</code></td><td>Sends queued webhooks.</td></tr>
<tr><td><code>scripts/reconcile.php [--fix]</code></td><td>Consistency check.</td></tr>
<tr><td><code>scripts/demo-reset.php</code></td><td>Rebuilds the demo (demo installations only).</td></tr>
</tbody></table></div>

<h2>Scheduled jobs</h2>
<div class="tablewrap"><table>
<thead><tr><th>Job</th><th>How often</th><th>Why</th></tr></thead>
<tbody>
<tr><td><code>php scripts/deliver-events.php</code></td><td>Every minute</td><td>Sends queued webhooks and retries failures. Without it events wait safely in the outbox.</td></tr>
<tr><td><code>php scripts/reconcile.php [--fix]</code></td><td>Nightly</td><td>Runs the consistency check and (with <code>--fix</code>) puts right what can be put right.</td></tr>
<tr><td><code>php scripts/demo-reset.php</code></td><td>Nightly, <strong>demo installations only</strong></td><td>Wipes and reseeds the demo. It refuses to run unless <code>DEMO_MODE</code> is on and the database name contains “demo”.</td></tr>
</tbody></table></div>
<pre><code>* * * * *   php /path/to/care-core/scripts/deliver-events.php &gt;&gt; /path/to/care-core/storage/deliver.log 2&gt;&amp;1
15 3 * * *  php /path/to/care-core/scripts/reconcile.php --fix &gt;&gt; /path/to/care-core/storage/reconcile.log 2&gt;&amp;1</code></pre>
<p>Nothing needs to run for a leaver’s access to end on the right day: that is checked on every request.</p>
<div class="note note--warn"><strong>Avoid overlapping runs.</strong> If your host runs jobs every minute, use a lock (<code>flock -n /tmp/cc.lock …</code>) for anything slow, and never schedule the demo reset every minute.</div>

<h2>Backups</h2>
<ul>
<li>Back up the <strong>database</strong> daily and keep several generations. Test restoring one.</li>
<li>Back up <code>storage/uploads/</code> with it: photos and signatures live there.</li>
<li>Keep a copy of <code>.env</code> and especially <code>APP_KEY</code> <strong>somewhere separate from the database backups</strong>. Without the key, the encrypted National Insurance and bank details cannot be read.</li>
</ul>

<h2>Upgrading</h2>
<ol>
<li>Back up the database and <code>storage/</code>.</li>
<li>Get the new code (for example <code>git pull</code>).</li>
<li>Run <code>php scripts/migrate.php</code>. On an existing database it applies each numbered file in <code>database/migrations/</code> that has not been applied, once, in order, and drops nothing. It prints what it applied, or “Nothing to apply”.</li>
<li>Check the sign-in page and the dashboard.</li>
</ol>
<p>Never run <code>migrate.php --fresh</code> on a database with real data: it drops every table. It refuses unless the database name contains “test” or “demo” (or demo mode is on).</p>

<h2>Monitoring</h2>
<ul>
<li><strong>Webhooks:</strong> Admin › Integrations shows delivered counts and “waiting or failed” for each address. A growing failed count means a receiver is down or rejecting.</li>
<li><strong>Consistency:</strong> check the dashboard’s “Needs attention” or the nightly reconcile log. It should be quiet.</li>
<li><strong>SCIM:</strong> each token shows when it was last used. A token that has not been used for a long time may mean provisioning has stopped.</li>
<li><strong>Disk:</strong> <code>storage/</code> grows slowly with photos and signatures.</li>
<li><strong>Expiring secrets:</strong> the Entra client secret and any SCIM token you set to expire.</li>
<li><strong>Logs:</strong> with <code>APP_DEBUG=false</code> errors are written to your server’s PHP error log, not shown to users.</li>
</ul>

<h2>Security maintenance</h2>
<ul>
<li>Keep PHP, MySQL and the server operating system patched.</li>
<li>Limit who can reach the database and the server; use strong, unique credentials.</li>
<li>Review administrator logins from time to time on Admin › Logins.</li>
<li>Rotate the Entra client secret before it expires, and revoke SCIM tokens you no longer use.</li>
</ul>

<h2>Moving to another server</h2>
<ol>
<li>Copy the code, the database, <code>storage/uploads/</code>, and <code>.env</code> (including the <em>same</em> <code>APP_KEY</code>).</li>
<li>Update <code>APP_URL</code>, and the redirect address in Entra, and the SCIM address in Entra if the address changed.</li>
<li>Re-create the scheduled jobs.</li>
</ol>
