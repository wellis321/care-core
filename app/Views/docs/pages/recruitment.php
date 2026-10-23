<h2>What it is for</h2>
<p>Recruitment is the staff-side counterpart of a referral. A <strong>job role</strong> says what a job is; a <strong>vacancy</strong> is a post to fill in that role; <strong>candidates</strong> move through interview to an offer; and <strong>hiring</strong> turns an accepted offer into a member of staff. It sits in front of <a href="/docs/joiners">starting someone</a> and uses exactly that process, so the person, their employment, their team, the onboarding checklist and the <code>employment.started</code> event all come from one place. Whatever listens to employment (your directory, devices, email, payroll) hears about a hire the same way it hears about any other joiner.</p>
<p>Managers and administrators can use it, and it can be switched off under <a href="/docs/admin-modules">Modules</a>.</p>

<h2>Job roles: the reference</h2>
<p>Under <strong>Recruitment → Job roles</strong> each role holds its title, an optional code, the job description and person specification, the <strong>role it reports to</strong>, the team it normally sits in, a default contract and hours, and a grade. A reporting line cannot loop. A role that is no longer used can be retired: vacancies already using it keep it.</p>
<h3>Checks before someone can start</h3>
<p>Each role lists what must be done before a person can start in it: two references, right to work, PVG membership, registration. Each is <em>required</em> or <em>optional</em>. They are copied to every candidate for that role, so each can be ticked off with a date and a note, and <strong>nobody can be hired until every required check is done</strong>. Adding a check to a role affects candidates added afterwards; those already added keep what they had.</p>

<h2>Vacancies</h2>
<p>A vacancy starts from a role, which supplies the title, team, contract and hours, and gets a reference such as <code>VAC-0001</code>. You choose how many <strong>posts</strong> there are, who the post <strong>reports to</strong>, and an optional closing date. It is a <strong>draft</strong> until it is <strong>opened</strong>; only an open vacancy takes candidates. It becomes <strong>filled</strong> automatically when the last post is filled by a hire, or <strong>closed</strong> if you stop without filling it (with the option to let candidates still in progress know). A closed vacancy can be reopened.</p>

<h2>Candidates</h2>
<p>A candidate is added to an open vacancy with their name and, if known, email, phone, date of birth and where they heard about it. They are <strong>not a person</strong> in People until they are hired, so unsuccessful candidates never appear in the people register. An application moves through these stages:</p>
<div class="tablewrap"><table>
<thead><tr><th>Stage</th><th>Meaning</th><th>Can go to</th></tr></thead>
<tbody>
<tr><td>Applied</td><td>Added to the vacancy.</td><td>Shortlisted, not taken forward, withdrawn</td></tr>
<tr><td>Shortlisted</td><td>Worth taking further.</td><td>Applied, interview, offer, not taken forward, withdrawn</td></tr>
<tr><td>Interview</td><td>With an interview date and notes.</td><td>Shortlisted, offer, not taken forward, withdrawn</td></tr>
<tr><td>Offer</td><td>An offer with a proposed start date. Then record that they <strong>accepted</strong>.</td><td>Interview, not taken forward, withdrawn</td></tr>
<tr><td>Hired</td><td>Reached only by hiring.</td><td>(final)</td></tr>
<tr><td>Not taken forward, Withdrawn</td><td>Out of the running, with an optional reason.</td><td>Reopened to Applied while the vacancy is open</td></tr>
</tbody></table></div>
<p>A candidate who is not hired can have their <strong>personal details removed</strong> once they are no longer needed. The fact that someone applied, and the stage reached, stays in the activity log without their name.</p>

<h2>Hiring</h2>
<p><strong>Hire</strong> is offered once an offer has been <em>accepted</em> and every <em>required check</em> is done, and the vacancy still has a free post. You confirm the start date, job title, employee reference, line manager and team (they default from the vacancy) and may create a login. Care Core then:</p>
<ul>
<li>finds out whether they are already a person here (by email, or by name and date of birth when exactly one matches) and, if so, starts a <em>new</em> employment for them, which is a rehire; otherwise it creates the person from the candidate’s details;</li>
<li>starts the employment, places them in the team, and creates the onboarding checklist, exactly as <a href="/docs/joiners">Starting someone</a> does;</li>
<li>marks the application <strong>Hired</strong>, linked to the person and the employment, and marks the vacancy <strong>Filled</strong> if that was its last post;</li>
<li>writes the activity log and raises <code>application.hired</code>, <code>employment.started</code> and (if new) <code>person.created</code> events.</li>
</ul>
<p>All of it happens together or not at all: if anything is wrong, nothing is created.</p>

<h2>From other systems</h2>
<p>Everything on this page is also in the <a href="/docs/api-reference">API</a> under <strong>Recruitment</strong>, using the <code>recruitment.read</code> and <code>recruitment.write</code> scopes, for example to receive applications from an applicant-tracking system or a careers page. Hiring through the API does not create a login: use the web page, or provision logins by <a href="/docs/scim">SCIM</a> or <a href="/docs/entra-signin">Microsoft sign-in</a>. The events are listed in the <a href="/docs/event-catalogue">event catalogue</a>.</p>
<p>Next: <a href="/docs/joiners">Starting someone</a>.</p>
