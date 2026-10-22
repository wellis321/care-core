<p><strong>Admin › Logins</strong> (administrators only) lists every login in your organisation and lets you create and disable them.</p>

<h2>The list</h2>
<p>For each login: the email address, the person it is linked to, the role and its status.</p>
<div class="tablewrap"><table>
<thead><tr><th>Status</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><strong>Invited</strong></td><td>Created, but the person has not yet chosen a password (or signed in with Microsoft).</td></tr>
<tr><td><strong>Active</strong></td><td>Can sign in, as long as the access end date (if any) has not passed.</td></tr>
<tr><td><strong>Disabled</strong></td><td>Cannot sign in. Set by an administrator, or by Entra when it removes someone.</td></tr>
</tbody></table></div>

<h2>Creating a login</h2>
<ol>
<li>Enter the <strong>email address</strong> (must be unique across Care Core).</li>
<li>Choose the <strong>role</strong>: administrator, manager or staff. See <a href="/docs/roles">Roles and permissions</a>.</li>
<li>Optionally choose the <strong>person</strong> it belongs to. A login linked to a person gets <em>My profile</em>, their own dashboard, and (for staff) automatic access ending when they leave. A login with no person has no profile page.</li>
<li>Choose <strong>Create</strong>.</li>
</ol>
<p>Care Core shows the <strong>invitation link once</strong> at the top of the next page. It is valid for 7 days and can be used once. Copy it and send it to the person yourself: Care Core does not send email. See <a href="/docs/signing-in">Signing in</a>.</p>
<div class="note"><strong>When you start someone as staff</strong> you can tick “create a login” in the joiner form instead. That creates the login and shows its invitation link in the same way.</div>

<h2>New invitation: a forgotten password or a lost link</h2>
<p>Choose <strong>New invitation</strong> against any login that is not disabled. Care Core issues a fresh one-time link (shown once, valid for 7 days) and returns the login to <em>invited</em>: <strong>the old password stops working immediately</strong>, and so does any session still open. Pass the link to the person; opening it lets them choose a new password. This is how a forgotten password is dealt with. Each use is written to the activity log.</p>

<h2>Disabling a login</h2>
<p>Choose <strong>Disable</strong> against the login. It stops working at once. You cannot disable your own login. Disabling is recorded in the activity log. To restore access, create the login again or, for a returning member of staff, use <a href="/docs/joiners">Start new employment</a> (which removes the end date from the existing login).</p>

<h2>You usually do not need this page</h2>
<p>Logins are created and ended for you by the joiner and leaver processes, and by Entra provisioning. Use this page for people who are not staff (for example an external auditor you want to give a manager login) and for corrections.</p>
