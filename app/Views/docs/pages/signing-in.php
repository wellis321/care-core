<h2>Signing in with a password</h2>
<p>Go to the sign-in page, enter your email address and password. Passwords must be at least 10 characters and can be as long as you like (up to 200). Care Core does not impose arbitrary character-mix rules: a long passphrase is stronger than a short complicated password.</p>

<h3>Lock-out protection</h3>
<ul>
<li>After <strong>5 wrong attempts</strong> for the same email address, or <strong>20</strong> from the same network address, within 15 minutes, sign-in is paused for that email or address until the 15 minutes have passed.</li>
<li>The message is the same whether the email exists or not, so the form cannot be used to discover who has an account.</li>
<li>A successful sign-in clears the count for that email address.</li>
</ul>

<h2>Changing your password</h2>
<p>Click your email address at the top right to open <strong>Your account</strong>. Enter your current password and the new one twice. The new password must be at least 10 characters and different from the current one. Logins that sign in with Microsoft have no password to change.</p>

<h2>Forgotten your password</h2>
<p>Ask an administrator to choose <strong>New invitation</strong> against your login (Admin › Logins). Care Core shows them a one-time link, valid for 7 days, which they pass to you. Opening it lets you choose a new password. At that moment your old password stops working, and so does any session that was still open. There is no “email me a reset link” yet because Care Core does not send email.</p>

<h2>Invitations</h2>
<p>When an administrator creates a login, or someone is started with a login option ticked, Care Core generates a one-time <strong>invitation link</strong>. It is shown <strong>once</strong>, on screen, to whoever created it. Care Core does not send email, so pass the link on yourself. The link:</p>
<ul>
<li>is valid for <strong>7 days</strong>;</li>
<li>can be used <strong>once</strong>: opening it lets the person choose a password, after which it stops working;</li>
<li>is stored only as a hash, so it cannot be recovered. If it is lost, an administrator creates a new login or invitation.</li>
</ul>

<h2>Signing in with Microsoft</h2>
<p>If your organisation has set it up, a <strong>Sign in with Microsoft</strong> button appears. You are taken to Microsoft, sign in with your work account, and come back signed in. Care Core does not create accounts this way: you must already have a login (created by an administrator, by joining you as staff with a login, or by your organisation’s Entra provisioning). See <a href="/docs/entra-signin">Sign in with Microsoft</a>.</p>

<h2>Who can still sign in</h2>
<p>A login works only while all of these are true:</p>
<ol>
<li>its status is <strong>active</strong> (not <em>invited</em> and not <em>disabled</em>);</li>
<li>its <em>access ends on</em> date, if there is one, has not passed. For a leaver this is their last day, so they can use Care Core on that day and not after.</li>
</ol>
<p>This is checked on <strong>every request</strong>, not just when you sign in. Someone who is signed in when their access ends is signed out on their next click.</p>

<h2>Sessions and signing out</h2>
<p>The session cookie is private to your browser, not readable by scripts on the page, only sent over HTTPS on a live site, and not sent on requests started from other websites. It ends when you close the browser or choose <strong>Sign out</strong>. The session identifier is replaced every time you sign in.</p>

<h2>The demo</h2>
<p>The public demo is a sandbox with made-up data that resets every night. Its three accounts share the password <code>DemoPass2026!</code>:</p>
<div class="tablewrap"><table>
<thead><tr><th>Account</th><th>Role</th><th>Good for trying</th></tr></thead>
<tbody>
<tr><td><code>admin@demo.lochview.test</code></td><td>Administrator</td><td>Everything, including Admin, integrations and the staff record</td></tr>
<tr><td><code>manager@demo.lochview.test</code></td><td>Manager (and a line manager)</td><td>The approvals queue, starting, moving and ending staff</td></tr>
<tr><td><code>staff@demo.lochview.test</code></td><td>Staff</td><td>My profile, the photo and signature, the limited view</td></tr>
</tbody></table></div>
<p>Please do not enter real personal information in the demo.</p>
<p>Next: <a href="/docs/dashboard">The dashboard</a>.</p>
