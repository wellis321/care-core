<p>Staff requests wait in a queue for review. The <strong>Approvals</strong> page lists them, grouped by person. A number beside <em>Approvals</em> in the menu shows how many are waiting for you.</p>

<h2>Who reviews what</h2>
<ul>
<li>A person’s <strong>current line manager</strong> reviews that person’s requests, whatever the manager’s own role.</li>
<li><strong>Managers and administrators</strong> can review anyone’s. This is the “HR at the top of the chain” route, and the way to cover when a line manager is away or the post is vacant.</li>
<li><strong>Nobody reviews their own</strong>, not even an administrator.</li>
<li>Requests from another organisation never appear.</li>
</ul>

<h2>Reviewing</h2>
<p>Each request shows what is being changed, who asked and when:</p>
<ul>
<li>For text and dates: the <strong>old value crossed out and the new one</strong>. Reviewers see the real National Insurance or bank number so they can check it against the paperwork. The person who asked sees it masked.</li>
<li>For a photo or signature: the <strong>new picture itself</strong>, shown beside the label.</li>
</ul>
<p>Choose <strong>Approve</strong> or <strong>Decline</strong>. When declining, you may type a reason (up to 300 characters); the person sees it on their profile.</p>

<h2>What approval does</h2>
<ol>
<li>The value is checked again (the rules may have changed since the request).</li>
<li>It is written to the live record. Photo and signature requests replace the previous file, which is deleted from storage.</li>
<li>The request is marked approved with who reviewed it and when.</li>
<li>The activity log records “Approved: Address line 1” (the field, not the value) and a <code>person.updated</code> event announces which fields changed.</li>
</ol>
<p>Declining deletes a pending picture from storage and records the reason. Requests that have already been dealt with cannot be approved twice.</p>

<h2>Why the person’s own page sends managers elsewhere</h2>
<p>If a manager opens <em>their own</em> staff record from the manager’s page, they are sent to <em>My profile</em>. That keeps their changes in the same approval route as everyone else’s. Administrators are exempt so that someone can always correct a record.</p>
<p>Next: <a href="/docs/teams">Teams</a>.</p>
