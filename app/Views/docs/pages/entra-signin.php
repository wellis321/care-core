<p>If your organisation uses Microsoft 365, staff can sign in to Care Core with their work account. Care Core uses <strong>OpenID Connect</strong> (the authorisation code flow with PKCE) against <strong>one Entra tenant</strong>.</p>

<h2>What you need</h2>
<ul>
<li>Permission to register applications in Microsoft Entra ID (Application Administrator or similar).</li>
<li>Access to Care Core’s settings file (<code>.env</code>) on the server.</li>
<li>Care Core served over <strong>HTTPS</strong>.</li>
</ul>

<h2>Step by step</h2>
<ol>
<li>In the Entra admin centre go to <strong>Identity › Applications › App registrations › New registration</strong>.</li>
<li>Name it “Care Core”. Under <strong>Supported account types</strong> choose <strong>Accounts in this organisational directory only</strong> (single tenant).</li>
<li>Under <strong>Redirect URI</strong> choose <strong>Web</strong> and enter <code>https://YOUR-CARE-CORE-ADDRESS/login/entra/callback</code>. (Admin › Integrations shows the exact address.)</li>
<li>After registering, copy the <strong>Application (client) ID</strong> and the <strong>Directory (tenant) ID</strong> from the Overview page.</li>
<li>Go to <strong>Certificates &amp; secrets › New client secret</strong>, create one, and copy its <strong>Value</strong> immediately (it is shown once).</li>
<li>Add to Care Core’s <code>.env</code>:
<pre><code>APP_URL=https://YOUR-CARE-CORE-ADDRESS
ENTRA_TENANT_ID=00000000-0000-0000-0000-000000000000
ENTRA_CLIENT_ID=00000000-0000-0000-0000-000000000000
ENTRA_CLIENT_SECRET=the-secret-value</code></pre></li>
<li>Reload the sign-in page. A <strong>Sign in with Microsoft</strong> button now appears. <strong>Admin › Integrations</strong> shows “On”.</li>
</ol>
<p>The default permissions (<em>User.Read</em>, plus the sign-in scopes <code>openid profile email</code>) are enough. Care Core never asks for access to mail, files or the directory.</p>

<h2>Who can sign in this way</h2>
<p><strong>Only people who already have a Care Core login.</strong> Signing in with Microsoft never creates an account. Logins come from the joiner process, an administrator, or <a href="/docs/scim">Entra provisioning</a>. Someone with a Microsoft account but no Care Core login is told their account “is not set up for Care Core”.</p>

<h2>How people are matched</h2>
<ol>
<li><strong>First sign-in.</strong> Care Core looks for a login whose email address equals the person’s Microsoft <strong>sign-in name</strong> (user principal name). It then <em>links</em> the login to the person’s permanent Microsoft identifier (tenant id plus object id).</li>
<li><strong>Every later sign-in</strong> matches on that permanent identifier. If the person’s email address changes, they still get their own account. And a different Microsoft account with a recycled or similar name <em>cannot</em> take it over.</li>
</ol>
<p>The “email” claim in the token is <strong>never</strong> used to find an account, because it is not verified by Microsoft and could be set to someone else’s address.</p>
<p>A login that is <em>invited</em> becomes <em>active</em> the first time its owner signs in with Microsoft, and the one-time invitation link is spent.</p>

<h2>When access ends</h2>
<p>Microsoft sign-in obeys the same rules as a password: a <em>disabled</em> login, or one whose access end date has passed, is refused with the same message. A leaver cannot get in with Microsoft the day after their last day.</p>

<h2>Switching passwords off</h2>
<p>Once Microsoft sign-in works you can require it for staff:</p>
<pre><code>PASSWORD_LOGIN=false</code></pre>
<p>Staff and managers can then only sign in with Microsoft. <strong>Administrators keep a password</strong> (under “Administrator password sign-in” on the page), so you can still get in if Microsoft is unavailable or the settings are wrong. Passwords stay on automatically if Microsoft sign-in is not configured, so you cannot lock yourself out by setting this early.</p>

<h2>How it is protected</h2>
<ul>
<li><strong>State and nonce</strong> tie the response to the sign-in you started, so a response cannot be replayed or forged. A sign-in must be completed within 10 minutes and can be used once.</li>
<li><strong>PKCE</strong> protects the authorisation code.</li>
<li>The ID token must have a valid <strong>RS256 signature</strong> from the tenant’s published keys (cached for an hour, and re-fetched if a key is unknown), the right <strong>issuer</strong>, <strong>tenant</strong> and <strong>audience</strong>, and be <strong>in date</strong>. Tokens signed with other algorithms (including <code>none</code>) are refused.</li>
<li>Connections to Microsoft check the server’s certificate and allow HTTPS only.</li>
<li>Single tenant only: tokens from any other tenant are refused.</li>
</ul>

<h2>If it does not work</h2>
<div class="tablewrap"><table>
<thead><tr><th>What you see</th><th>Likely cause</th></tr></thead>
<tbody>
<tr><td>No Microsoft button</td><td>One of the three <code>ENTRA_</code> settings, or <code>APP_URL</code>, is missing.</td></tr>
<tr><td>Microsoft shows “redirect URI mismatch”</td><td>The Redirect URI in Entra is not exactly the address shown in Admin › Integrations (https, no trailing slash).</td></tr>
<tr><td>“Microsoft would not complete the sign-in”</td><td>The client secret is wrong or has expired. Create a new one and update <code>.env</code>.</td></tr>
<tr><td>“Your Microsoft sign-in could not be verified”</td><td>The tenant id or client id is wrong, or the server’s clock is badly out.</td></tr>
<tr><td>“Your Microsoft account is not set up for Care Core”</td><td>There is no login whose email matches their sign-in name, or it is disabled or ended. Create or fix the login.</td></tr>
<tr><td>“That sign-in took too long or was not started here”</td><td>Took over 10 minutes, or cookies are blocked. Try again from the Care Core page.</td></tr>
</tbody></table></div>
<p>Client secrets expire (up to 24 months). Put the date in your calendar.</p>
