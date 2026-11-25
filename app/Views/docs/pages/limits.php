<p>Care Core is deliberately focused. Here is what it does <em>not</em> do yet, so you can plan around it. Items marked <em>planned</em> are the natural next steps.</p>

<h2>Not built yet</h2>
<div class="tablewrap"><table>
<thead><tr><th>Area</th><th>Today</th></tr></thead>
<tbody>
<tr><td><strong>Email</strong></td><td>Care Core sends none. Invitation links are shown once on screen and passed on by hand; there is no “email me a reset link”; approvals, leave decisions, interview invitations and due dates are not emailed (interview invitations are text you copy into your own email). <em>Planned.</em></td></tr>
<tr><td><strong>Ongoing synchronisation with older systems</strong></td><td>Importing is one-way and meant for a migration (re-runs pick up new people, not changes to people already imported). There is no CSV import: build a JSON file from a spreadsheet with a short script (see <a href="/docs/import-format">the import format</a>). Photos and signatures come across only from the command line.</td></tr>
<tr><td><strong>Editing compliance records in place</strong></td><td>Registrations, supervisions, appraisals and learning can be added and removed, not edited. Remove and re-add. <em>Planned.</em></td></tr>
<tr><td><strong>Documents and certificates</strong></td><td>PDF, Word, PNG and JPEG files up to 10 MB can be kept on a staff record, with an expiry date and optional sharing with the person (see <a href="/docs/staff-documents">Staff documents</a>). Files are not scanned for viruses and not searched. Nobody is emailed when one expires.</td></tr>
<tr><td><strong>Future-dated moves</strong></td><td>A change of role or team must take effect today or earlier. <em>Planned.</em></td></tr>
<tr><td><strong>Leave, rotas, payroll</strong></td><td>Leave can be requested, decided and shown on a team calendar (see <a href="/docs/leave">Leave and absence</a>), but days are Monday to Friday with no bank holidays taken off, there is no pro-rata calculation or carry-over, and a request can be decided by any manager. Rotas and payroll are out of scope.</td></tr>
<tr><td><strong>Care planning</strong></td><td>Care needs and contacts are operational facts. There are no care plans, daily notes, medication records or incident reports.</td></tr>
<tr><td><strong>Groups and role mapping from Entra</strong></td><td>Roles are set in Care Core, not derived from Entra groups. <em>Planned.</em></td></tr>
<tr><td><strong>Retention and subject access tools</strong></td><td>There is a list of leavers past a retention period you set and a one-file “everything held about this person” export (see <a href="/docs/staff-documents">Staff documents</a>). There is no automatic archiving or deletion, and the export covers Care Core only.</td></tr>
<tr><td><strong>Reporting</strong></td><td>Beyond the dashboard, the due-soon list and the activity log, there are no report builders or exports.</td></tr>
<tr><td><strong>Multiple organisations in one sign-in</strong></td><td>A login belongs to one organisation.</td></tr>
<tr><td><strong>Languages</strong></td><td>British English only.</td></tr>
</tbody></table></div>

<h2>Known behaviours worth knowing</h2>
<ul>
<li>SCIM provisioning starts a person’s employment <em>today</em>. For a future start date, start them in Care Core.</li>
<li>Editing a checklist does not change tasks already created.</li>
<li>The email address on a person and on their login are separate. Changing one does not change the other automatically.</li>
<li>Nobody is deleted: to remove a person entirely, archive from the database under your retention policy.</li>
<li>Microsoft sign-in works with one Entra tenant per installation.</li>
</ul>

<h2>How to tell us what you need</h2>
<p>The order of the roadmap follows what organisations need most. Tell the people running your installation which of these would help you most.</p>
