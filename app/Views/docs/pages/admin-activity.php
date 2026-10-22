<p><strong>Activity</strong> (managers and administrators) is the audit trail. Every change made in Care Core adds a line here, written in the <strong>same transaction</strong> as the change, so there can never be a change without a record or a record of a change that did not happen. The list only ever grows.</p>

<h2>What each line shows</h2>
<ul>
<li><strong>When</strong>: date and time.</li>
<li><strong>What</strong>: a plain-English summary (“Started Nina Walker as Support Worker”, “Staff record updated: Address line 1, Postcode”). Where it is about a person, the name links to their profile.</li>
<li><strong>Who</strong>: the signed-in user, or a system process such as SCIM or the demo reset.</li>
</ul>

<h2>What it records</h2>
<p>Starting, moving and ending staff; edits to people; staff record changes, approvals and declines; registrations, supervisions, appraisals and learning added or removed; team changes; support, key worker, care need and contact changes; tasks completed; logins created and disabled; Microsoft sign-ins; webhook and SCIM token creation; consistency fixes.</p>
<div class="note"><strong>Values are not logged for sensitive fields.</strong> For National Insurance, bank details and similar, the log says which <em>fields</em> changed, never the old or new value, so the log itself cannot leak them.</div>

<h2>Reading it</h2>
<p>The page shows the newest entries first, with <strong>Newer</strong> and <strong>Older</strong> buttons. A person’s own profile also shows the entries about them.</p>

<h2>Keeping it</h2>
<p>The log is designed to be kept. There is currently no automatic deletion. Decide your retention period with your data protection lead and archive from the <code>audit_log</code> table accordingly. See <a href="/docs/security">Security and privacy</a>.</p>
