<h2>General</h2>
<h3>Is Care Core a replacement for our HR system, rota or care planning system?</h3>
<p>It is the system of record for <em>who is who</em>: people, employment, teams, key workers, and the staff and compliance records around them. It does not do payroll, rotas, leave booking or clinical care plans. It tells those systems about changes so they stay right. See <a href="/docs/limits">What it does not do yet</a>.</p>
<h3>Why is nothing ever deleted?</h3>
<p>Because the history is the point. “Who was in this team in March?” and “who was this person’s key worker last year?” are questions inspectors ask. People and relationships are ended, not removed. Your retention policy decides how long to keep them: see <a href="/docs/security">Security and privacy</a>.</p>
<h3>What does “last day” mean?</h3>
<p>The end date is the last day someone is employed or in a team, inclusive. A leaver whose last day is Friday has access on Friday and not on Saturday.</p>

<h2>Staff</h2>
<h3>Someone has left and come back. Do I create them again?</h3>
<p>No. Open their profile and choose <strong>Start new employment</strong>. It is a new employment for the same person, so their history is kept and no duplicate is created.</p>
<h3>Can I record a future promotion now?</h3>
<p>Not yet. A change of role must be effective today or earlier. Record it on the day it happens. (Future-dated moves are planned.) A <em>leaver</em> can be recorded with a future last day.</p>
<h3>A member of staff cannot see the Staff record page.</h3>
<p>That page is for managers and administrators. Staff use <strong>My profile</strong>.</p>
<h3>Why is my own staff record sending me to My profile?</h3>
<p>So your changes go through approval like everyone else’s. Administrators are exempt.</p>
<h3>Who approves my changes if my line manager has left?</h3>
<p>Any manager or administrator can. Ask HR, or update your line manager (an administrator or manager can do this with <em>Change role or team</em>).</p>
<h3>Why does the National Insurance box look empty?</h3>
<p>For safety, it never shows the stored number. Leave it empty to keep it; type a number to request a change.</p>

<h2>Sign-in</h2>
<h3>Can I bring in the data from the old Staff, People and Team services?</h3>
<p>Yes. Exporters read the old databases and an importer checks everything first (a dry run changes nothing), then brings it in through the same rules as typed data. See <a href="/docs/import-migrating">Moving from the older Staff, People and Team services</a>.</p>
<h3>I forgot my password.</h3>
<p>Ask an administrator for a <strong>New invitation</strong> (Admin › Logins). They give you a link to choose a new one. To change a password you know, click your email address at the top right.</p>
<h3>I never received my invitation.</h3>
<p>Care Core does not send email. The invitation link is shown to whoever created the login, once. If it is lost, an administrator creates the login again (or, for Microsoft sign-in, the person simply uses the Microsoft button).</p>
<h3>“Too many sign-in attempts”.</h3>
<p>Wait 15 minutes. It protects against password guessing.</p>
<h3>Why can’t this person sign in with Microsoft?</h3>
<p>They need a Care Core login first, with the same email as their Microsoft sign-in name. See <a href="/docs/entra-signin">Sign in with Microsoft</a>.</p>
<h3>What happens to someone’s access when they leave?</h3>
<p>It ends after their last day automatically. There is nothing to remember and no job that has to run.</p>

<h2>Teams and care</h2>
<h3>Why can’t I add a person we support to this team?</h3>
<p>It is marked <strong>staff only</strong>.</p>
<h3>Why can’t I make someone a key worker?</h3>
<p>Key workers must be <em>current staff</em>, and the other person must be <em>receiving support</em>. Nobody can be their own key worker.</p>
<h3>Why did a key worker link end by itself?</h3>
<p>Because the key worker left, or the person’s support ended. A task was created if a new key worker is needed.</p>

<h2>Integrations</h2>
<h3>Can another system read and change Care Core?</h3>
<p>Yes, through the <a href="/docs/api">API</a>. An administrator creates a key with only the scopes it needs, and the system calls Care Core over HTTPS. The same rules, activity log and events apply as in the web interface.</p>
<h3>Can the API create logins or approve a staff member’s request?</h3>
<p>No. Access and approvals stay with people. The API can read the logins list and see what is waiting for approval.</p>
<h3>Entra says provisioning failed.</h3>
<p>Check the token has not been revoked, that the tenant URL ends in <code>/scim/v2</code>, and that <code>userName</code> is an email address. See <a href="/docs/scim">Provisioning from Entra</a>.</p>
<h3>My webhook receiver sees the same event twice.</h3>
<p>That is expected: delivery is at least once. De-duplicate on the event id. See <a href="/docs/webhooks">Events and webhooks</a>.</p>
<h3>Why do events not contain names?</h3>
<p>By design, so personal and health information does not travel through logs and queues. Look the person up by their UUID.</p>

<h2>Data protection</h2>
<h3>Can you delete a person for a data protection request?</h3>
<p>Not from the interface yet. This is an organisational decision involving your lawful basis and retention duties. See <a href="/docs/security">Security and privacy</a> and <a href="/docs/limits">limits</a>.</p>
