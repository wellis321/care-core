<?php use App\Core\Config; ?>
<div class="hero">
  <p class="eyebrow">About Care Core</p>
  <h1>One record of your people. Joiners, movers and leavers change everything at once.</h1>
  <p class="lede">Care Core is the system of record for a care organisation: the staff who work there, the people they support, and the teams that connect them. Start someone, change their role or record their leaving once, and everything that depends on it changes with it.</p>
  <p class="actions"><a class="btn btn-primary" href="/login"><?= Config::demo() ? 'Try the demo' : 'Sign in' ?></a> <a class="btn btn-secondary" href="/features">See what it does</a> <a class="btn btn-secondary" href="/docs">Read the documentation</a></p>
</div>

<section class="prose" style="max-width:52rem">
  <h2>The problem</h2>
  <p>The same facts live in several places: an HR system, a rota, a training tracker, a care system, a spreadsheet of registrations. When someone leaves, somebody has to remember to update every one of them. Things get missed, and the failures are the ones inspectors worry about: a leaver still in a team, still listed as someone’s key worker, and still able to sign in.</p>
  <h2>The idea</h2>
  <p>Keep one record of each person, and describe everything else as <em>dated relationships</em> with that person: employment, team membership, support, key worker links, a login. Nothing is overwritten and nothing is deleted. Joiner, mover and leaver are single actions, saved together or not at all, and every change is announced to the systems that care.</p>
</section>

<div class="cols3">
  <div><h3>One person, one history</h3><p>A rehire is a new employment for the same person. Moving team, becoming a key worker, returning after a break: all kept, all dated. Ask “who was in this team in March?” and get an answer.</p></div>
  <div><h3>Leavers cannot be half-leaving</h3><p>Recording a last day ends the employment, the team places and the key worker links, creates the follow-up tasks, and sets the login to stop after that day. All in one go, with a preview first. No overnight job has to succeed.</p></div>
  <div><h3>Staff look after their own details</h3><p>Staff update their address, emergency contact, photo and signature themselves. Sensitive changes wait for their line manager, with the old and new values side by side.</p></div>
  <div><h3>Connects to Microsoft 365</h3><p>Staff sign in with their work account. Entra provisioning creates joiners and ends leavers automatically.</p></div>
  <div><h3>Tells your other systems</h3><p>Every change becomes an event delivered as a signed webhook, with retries, and carrying identifiers and dates only: never names or health information.</p></div>
  <div><h3>Proves it is consistent</h3><p>A nightly check finds anything that disagrees with itself (a leaver still in a team, a key worker who has left) and can put it right.</p></div>
</div>

<section class="prose" style="max-width:52rem">
  <h2>Who it is for</h2>
  <ul>
    <li><strong>Registered managers and team leaders</strong> who need to know who is on their team today and what is due.</li>
    <li><strong>HR and administrators</strong> who start, move and end staff and keep the compliance records inspectors ask for.</li>
    <li><strong>Staff</strong>, who see their own job, team and records and keep their details current.</li>
    <li><strong>IT</strong>, who connect Microsoft Entra and the other systems.</li>
  </ul>
  <h2>What is in it</h2>
  <p>A core of <strong>People</strong> (profiles, logins, tasks, the activity log) and three modules you can switch on or off: <strong>Staff</strong> (the full staff record, joiner, mover and leaver, registrations, supervision, appraisals, learning, self-service), <strong>Teams</strong> (hierarchies and membership over time) and <strong>Care</strong> (people we support, needs, contacts and key workers).</p>
  <h2>What it is not</h2>
  <p>Care Core is not payroll, a rota, a leave booking system or a care-planning record. It is the dependable source of <em>who is who</em> that those systems should trust. <a href="/docs/limits">What it does not do yet</a> is set out plainly.</p>
  <h2>See it for yourself</h2>
  <?php if (Config::demo()): ?>
  <p>The demo has a fictional care organisation, “Loch View Care”, with staff, teams, people we support and records already in it. Sign in as <code>manager@demo.lochview.test</code>, <code>admin@demo.lochview.test</code> or <code>staff@demo.lochview.test</code> with the password <code>DemoPass2026!</code>. It resets every night, so please explore freely, and do not enter real personal information.</p>
  <?php else: ?>
  <p>Ask the people who run your installation for a demonstration, or read the <a href="/docs">documentation</a>.</p>
  <?php endif; ?>
  <p class="actions"><a class="btn btn-primary" href="/login">Sign in</a> <a class="btn btn-secondary" href="/docs/overview">Read the overview</a></p>
</section>
