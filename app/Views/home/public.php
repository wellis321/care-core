<?php
/** The public front page. Everything claimed here is what Care Core does today (see /features, /security and the documentation); what it does not do yet is on this page too. */
use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;
use App\Modules\Identity\DemoEntry;

$demo = Config::demo();
$mail = 'mailto:hello@findcarescotland.com?subject=' . rawurlencode('Care Core: a walkthrough');
$shot = static fn(string $n, string $alt, int $w = 1080, int $h = 708, bool $eager = false): string =>
    '<img src="/assets/product/' . $n . '.webp?v=' . (int) @filemtime(ROOT_PATH . '/public/assets/product/' . $n . '.webp') . '" alt="' . View::e($alt) . '" width="' . $w . '" height="' . $h . '"' . ($eager ? '' : ' loading="lazy"') . ' decoding="async">';
?>
<div class="rg">

<!-- ═══ Hero: one change, everything follows ═══════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-hero" id="top" aria-labelledby="hero-h">
  <div class="rg-wrap rg-hero-grid">
    <div class="rg-hero-copy">
      <p class="rg-eyebrow">The system of record for care organisations</p>
      <h1 id="hero-h">One record of your people. <em>Change it once.</em></h1>
      <p class="rg-lead">Care Core holds the staff who work for you, the people they support and the teams between them. Start someone, move them, or record their leaving once, and everything that depends on it follows, in the same step.</p>
      <div class="rg-cta">
        <?php if ($demo): ?><a class="rg-btn rg-btn-ink" href="#demo">Try it in ten seconds</a><?php else: ?><a class="rg-btn rg-btn-ink" href="<?= View::e($mail) ?>">Book a walkthrough</a><?php endif; ?>
        <a class="rg-btn rg-btn-line" href="#lifecycle">Watch a leaver, end to end</a>
      </div>
      <p class="rg-honest">A working demonstration with invented people.</p>
    </div>

    <div class="rg-stage" role="group" aria-label="Recording a leaver in Care Core">
      <div class="rg-card rg-card-main">
        <span class="rg-tab">Leaver</span>
        <div class="rg-shot"><?= $shot('leaver', 'The leaver screen: what will end on the last day, shown before anything is confirmed', 1080, 708, true) ?></div>
      </div>
      <ol class="rg-ticks" aria-label="What happens when you confirm">
        <li class="t1"><span class="rg-tick" aria-hidden="true"></span>Employment ended</li>
        <li class="t2"><span class="rg-tick" aria-hidden="true"></span>Team places ended, history kept</li>
        <li class="t3"><span class="rg-tick" aria-hidden="true"></span>Key worker links ended, tasks created</li>
        <li class="t4"><span class="rg-tick" aria-hidden="true"></span>Login set to stop after the last day</li>
        <li class="t5"><span class="rg-tick" aria-hidden="true"></span>Connected systems told</li>
      </ol>
    </div>
  </div>
</section>

<!-- ═══ The hub ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-sec rg-hub" id="hub" aria-labelledby="hub-h">
  <div class="rg-wrap">
    <p class="rg-eyebrow rg-on-ink">The centre of the suite</p>
    <h2 id="hub-h">Everything else listens to Care Core.</h2>
    <p class="rg-lead rg-on-ink rg-narrow">Change a person once here and the products that depend on them find out, with identifiers and dates only, never names or health details in the event itself. Each product works alone; together they stop you keeping the same fact in several places.</p>

    <figure class="sm-diagram"><svg viewBox="0 0 960 560" role="img" aria-labelledby="sm-dia-t sm-dia-d"><title id="sm-dia-t">How the suite joins up around Care Core</title><desc id="sm-dia-d">Care Core sits in the centre. Care Plans, Digital ID, Policy Core, Referrals, Contracts and Strategic Plans are each connected to it today.</desc><g class="sm-rings"><circle cx="480" cy="280" r="110"/><circle cx="480" cy="280" r="170"/><circle cx="480" cy="280" r="234"/></g><g><path class="sm-live" d="M216 106 C340 106 380 220 424 250"/><path class="sm-live" d="M216 280 L406 280"/><path class="sm-live" d="M216 454 C340 454 380 340 424 310"/><path class="sm-live" d="M744 106 C620 106 580 220 536 250"/><path class="sm-live" d="M744 280 L554 280"/><path class="sm-live" d="M744 454 C620 454 580 340 536 310"/></g><a class="sm-link" href="https://core.findcarescotland.com/" aria-label="Care Core: the record"><g class="sm-hub"><circle cx="480" cy="280" r="74"/><text x="480" y="276" class="sm-ht">Care Core</text><text x="480" y="300" class="sm-hs">you are here</text></g></a><a class="sm-link" href="https://care.findcarescotland.com/" aria-label="Care Plans: care records"><g class="sm-node"><rect x="40" y="70" width="176" height="72" rx="8"/><text x="128" y="101" class="sm-nt">Care Plans</text><text x="128" y="124" class="sm-ns">care records</text></g></a><a class="sm-link" href="https://id.findcarescotland.com/" aria-label="Digital ID: staff cards and training"><g class="sm-node"><rect x="40" y="244" width="176" height="72" rx="8"/><text x="128" y="275" class="sm-nt">Digital ID</text><text x="128" y="298" class="sm-ns">staff cards and training</text></g></a><a class="sm-link" href="https://policies.findcarescotland.com/" aria-label="Policy Core: policies and reading"><g class="sm-node"><rect x="40" y="418" width="176" height="72" rx="8"/><text x="128" y="449" class="sm-nt">Policy Core</text><text x="128" y="472" class="sm-ns">policies and reading</text></g></a><a class="sm-link" href="https://core.findcarescotland.com/" aria-label="Referrals: held in Care Core"><g class="sm-node"><rect x="744" y="70" width="176" height="72" rx="8"/><text x="832" y="101" class="sm-nt">Referrals</text><text x="832" y="124" class="sm-ns">held in Care Core</text></g></a><a class="sm-link" href="https://contracts.findcarescotland.com/public/index.php" aria-label="Contracts: reads the people"><g class="sm-node"><rect x="744" y="244" width="176" height="72" rx="8"/><text x="832" y="275" class="sm-nt">Contracts</text><text x="832" y="298" class="sm-ns">reads the people</text></g></a><a class="sm-link" href="https://strategic.findcarescotland.com/" aria-label="Strategic Plans: teams own projects"><g class="sm-node"><rect x="744" y="418" width="176" height="72" rx="8"/><text x="832" y="449" class="sm-nt">Strategic Plans</text><text x="832" y="472" class="sm-ns">teams own projects</text></g></a></svg><figcaption>Every line is connected today. Click any product to visit it. <a href="https://findcarescotland.com/software/suite">See the whole suite, from referral to care delivered</a>.</figcaption></figure>

    <div class="rg-hub-cols">
      <div>
        <h3>Working today</h3>
        <ul class="rg-list">
          <li><strong>Care Plans</strong> turns your training records into specialist skills with their expiry dates, so a rota can be checked against them.</li>
          <li><strong>Digital ID</strong> shows a member of staff's current training and skills, read from Care Core, on their own card.</li>
          <li><strong>Strategic Plans</strong> projects are owned by one of your teams, picked from Care Core, and show who is in the team today.</li>
          <li><strong>Contracts</strong> reads the people you support from Care Core, so each person is entered once and their contracts sit beside the support they pay for.</li>
          <li><strong>Referrals</strong> recorded and decided here, so they exist before the person arrives. Care Plans reads them, with the specialist skills they call for, and a person linked here is linked there.</li>
          <li><strong>Care Plans</strong> reads people supported, key workers and leavers (signed events plus a daily catch-up) and writes plan-review reminders back as tasks carrying no names.</li>
          <li><strong>Digital ID</strong> takes staff identity: name, photo, signature and status.</li>
          <li><strong>Policy Core</strong> reads the staff list.</li>
          <li><strong>Microsoft Entra</strong> signs staff in (OpenID Connect with PKCE) and provisions joiners and leavers through SCIM. Nobody is created by signing in.</li>
        </ul>
      </div>
      <div>
        <h3>Coming next</h3>
        <ul class="rg-list rg-list-next">
          <li><strong>Deeper links between the products,</strong> such as contracts beside each person's referral and plan goals tied to services.</li>
          <li><strong>Retention tools and a subject-access export,</strong> so you can answer a request for a person's information from one place.</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ═══ The failures ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-sec rg-failures" aria-labelledby="fail-h">
  <div class="rg-wrap rg-failures-grid">
    <div>
      <p class="rg-eyebrow rg-on-ink">The problem</p>
      <h2 id="fail-h">The same facts live in five places. When someone leaves, somebody has to remember all five.</h2>
    </div>
    <div class="rg-failures-body">
      <p>An HR system, a rota, a training tracker, a care system, a spreadsheet of registrations. Things get missed, and the failures are the ones inspectors worry about:</p>
      <ul class="rg-fails">
        <li>a leaver still in a team</li>
        <li>still listed as someone&rsquo;s key worker</li>
        <li>and still able to sign in</li>
      </ul>
      <p>Care Core makes each of those impossible by design. A leaver&rsquo;s login stops the day after their last day because every request is checked against that date, not because a nightly job remembered.</p>
    </div>
  </div>
</section>

<!-- ═══ Stories ════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<div id="story"></div>

<section class="rg-row" id="lifecycle" aria-labelledby="s1-h">
  <div class="rg-wrap rg-row-grid">
    <div class="rg-copy">
      <p class="rg-num" aria-hidden="true">1</p>
      <h2 id="s1-h">Joiners, movers and leavers, in one go.</h2>
      <p class="rg-benefit">A new starter is the person, their job, their team, an optional login and the onboarding checklist, saved together or not at all. A leaver gets a preview first: you see what will end, and what follow-up tasks will be created, before you confirm anything.</p>
      <ul class="rg-points">
        <li><strong>Nobody is half-leaving.</strong> Recording a last day ends the employment, the team places and the key worker links, creates the tasks, and schedules the login to stop.</li>
        <li><strong>The things a computer cannot do</strong> become tasks with a due date and an owner, so they are not forgotten.</li>
        <li><strong>No overnight job has to succeed.</strong> Access ends because it is checked against the date, every time.</li>
      </ul>
    </div>
    <div class="rg-visual">
      <div class="rg-card rg-reveal"><span class="rg-tab">Leaver preview</span><div class="rg-shot"><?= $shot('leaver', 'Record leaving: team places, key worker links, reports and logins that will change, shown before confirming', 1080, 708) ?></div></div>
      <div class="rg-card rg-card-small rg-reveal rg-late"><span class="rg-tab">New starter</span><div class="rg-shot"><?= $shot('joiner', 'The new starter form: the person, the job and the team set up together', 1080, 708) ?></div></div>
    </div>
  </div>
</section>

<section class="rg-row rg-row-flip" aria-labelledby="s2-h">
  <div class="rg-wrap rg-row-grid">
    <div class="rg-copy">
      <p class="rg-num" aria-hidden="true">2</p>
      <h2 id="s2-h">One person, many relationships. History never overwritten.</h2>
      <p class="rg-benefit">A person is created once and never deleted. Being staff, being supported, being in a team or being someone&rsquo;s key worker are relationships with start and end dates. A rehire, a move, a return after a break is a new row.</p>
      <ul class="rg-points">
        <li><strong>Ask &ldquo;who was in this team in March?&rdquo;</strong> and get an answer.</li>
        <li><strong>Teams in a hierarchy</strong> of services, teams and sub-teams, some marked staff only so people we support cannot be added.</li>
        <li><strong>One record for everyone:</strong> staff, people we support and leavers, in one searchable directory.</li>
      </ul>
    </div>
    <div class="rg-visual">
      <div class="rg-card rg-reveal"><span class="rg-tab">Teams</span><div class="rg-shot"><?= $shot('teams', 'Teams as a hierarchy of services and sub-teams, with staff-only teams marked', 1080, 708) ?></div></div>
      <div class="rg-card rg-card-small rg-reveal rg-late"><span class="rg-tab">People</span><div class="rg-shot"><?= $shot('people', 'One directory: staff, people we support and leavers', 1080, 708) ?></div></div>
    </div>
  </div>
</section>

<section class="rg-row" aria-labelledby="s3-h">
  <div class="rg-wrap rg-row-grid">
    <div class="rg-copy">
      <p class="rg-num" aria-hidden="true">3</p>
      <h2 id="s3-h">Staff look after their own details. A second pair of eyes checks the sensitive ones.</h2>
      <p class="rg-benefit">Staff keep their address, emergency contact, photo and signature up to date from My profile. Phone and emergency contact save at once; name, address, National Insurance, bank details, photo and signature wait for their line manager or HR, who see old and new side by side.</p>
      <ul class="rg-points">
        <li><strong>Nobody can approve their own request.</strong></li>
        <li><strong>National Insurance and bank details are encrypted</strong> in the database (AES-256-GCM), and never written to the log or to outgoing events.</li>
        <li><strong>Less chasing,</strong> fewer out-of-date addresses, and a record of who approved what.</li>
      </ul>
    </div>
    <div class="rg-visual">
      <div class="rg-card rg-reveal"><span class="rg-tab">Approvals</span><div class="rg-shot"><?= $shot('approvals', 'Approvals: an address change shown with the old value struck through and the new one beside it', 1080, 708) ?></div></div>
    </div>
  </div>
</section>

<section class="rg-row rg-row-flip" aria-labelledby="s4-h">
  <div class="rg-wrap rg-row-grid">
    <div class="rg-copy">
      <p class="rg-num" aria-hidden="true">4</p>
      <h2 id="s4-h">Everything a staff record needs, ready for the questions you will be asked.</h2>
      <p class="rg-benefit">Registrations such as SSSC, supervisions, appraisals, and learning and qualifications with expiry dates and a mandatory flag sit at the bottom of the staff record, each with its list and its Add form. They are the four kinds of record an inspector or auditor asks about.</p>
      <ul class="rg-points">
        <li><strong>Terms of employment</strong> (working time, TUPE, apprenticeship, visa, leave) and the staff member&rsquo;s own details in one place.</li>
        <li><strong>Every addition is logged</strong> and announced as an event, so other systems can listen.</li>
        <li><strong>A recruitment pipeline</strong> from job role to vacancy to candidate, including the checks needed before someone can start.</li>
      </ul>
      <p class="rg-fine">Compliance records can be added and removed but not edited in place yet: remove and re-add.</p>
    </div>
    <div class="rg-visual">
      <div class="rg-card rg-reveal"><span class="rg-tab">Staff record</span><div class="rg-shot"><?= $shot('staff', 'A staff member: details, tasks, history, employment and the staff record', 1080, 708) ?></div></div>
      <div class="rg-card rg-card-small rg-reveal rg-late"><span class="rg-tab">Dashboard</span><div class="rg-shot"><?= $shot('dashboard', 'The dashboard: staff, people we support, open and overdue tasks, who is starting and leaving soon', 1080, 693) ?></div></div>
    </div>
  </div>
</section>

<section class="rg-row" aria-labelledby="s5-h">
  <div class="rg-wrap rg-row-grid rg-row-single">
    <div class="rg-copy">
      <p class="rg-num" aria-hidden="true">5</p>
      <h2 id="s5-h">The people we support, kept separate and sensible.</h2>
      <p class="rg-benefit">Support episodes, key workers, contacts and care needs, available to managers and administrators only. It is the operational facts needed to run the service. It is not a care plan, and it says so.</p>
      <ul class="rg-points rg-points-wide">
        <li><strong>NHS number checked</strong> as it is typed: ten digits and a correct check digit.</li>
        <li><strong>One episode at a time,</strong> with the whole history kept when support ends or starts again.</li>
        <li><strong>Ending support</strong> ends the key worker links and team places the same day, so nobody stays linked to someone who is no longer supported.</li>
        <li><strong>Care planning belongs in Care Plans,</strong> which reads these people from here.</li>
      </ul>
    </div>
  </div>
</section>

<!-- ═══ Trust and honest limits ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-sec rg-trust" id="trust" aria-labelledby="trust-h">
  <div class="rg-wrap rg-trust-grid">
    <div>
      <p class="rg-eyebrow">Security and privacy</p>
      <h2 id="trust-h">Built to be trusted with people&rsquo;s details.</h2>
      <p class="rg-lead-dark">Care Core holds information about staff and about people who receive care. This is what protects it, and where the responsibility passes to you.</p>
    </div>
    <ol class="rg-six">
      <li><strong>Access by role.</strong> Administrator, manager and staff, enforced on the server for every page. One organisation can never see another&rsquo;s records.</li>
      <li><strong>Encrypted where it matters.</strong> National Insurance and bank details (AES-256-GCM); passwords, invitations and tokens stored only as hashes.</li>
      <li><strong>A second pair of eyes</strong> on sensitive changes, and nobody approves their own.</li>
      <li><strong>Access that ends on time.</strong> A leaver&rsquo;s login stops after their last day because it is checked against the date.</li>
      <li><strong>A complete audit trail.</strong> Who, what and when, written in the same step as the change. Sensitive values are never logged or sent out.</li>
      <li><strong>Safe sign-in.</strong> Microsoft sign-in with full token checks, rate-limited passwords, private cookies and protection against forged forms.</li>
    </ol>
  </div>
  <div class="rg-wrap rg-limits">
    <h3>On the roadmap</h3>
    <p>Retention tools and a subject-access export are planned, and the connected suite is growing: deeper links between the products next. You remain the data controller, and Care Core gives you the tools to act as one.</p>
  </div>
</section>

<!-- ═══ Developers ═════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-sec rg-dev" id="developers" aria-labelledby="dev-h">
  <div class="rg-wrap rg-dev-grid">
    <div>
      <p class="rg-eyebrow">For developers and your IT lead</p>
      <h2 id="dev-h">An API that follows the same rules as the screens.</h2>
      <p class="rg-lead-dark">More than 60 endpoints in a versioned REST API, calling the same domain services as the web interface, so the same rules, audit trail and events apply.</p>
      <p><a class="rg-link" href="/docs/api">Read the API guide</a> &middot; <a class="rg-link" href="/docs">Documentation (36 pages)</a></p>
    </div>
    <ul class="rg-dev-list">
      <li><strong>Scoped bearer keys</strong> with per-key rate limits, address allow-lists and expiry.</li>
      <li><strong>Idempotent POSTs,</strong> cursor pagination and problem+json errors.</li>
      <li><strong>Signed webhooks with retries,</strong> carrying identifiers and dates only.</li>
      <li><strong>OpenAPI 3.1</strong> generated from the route table, so it cannot drift.</li>
      <li><strong>SCIM and Entra</strong> for joiners and leavers pushed in.</li>
    </ul>
  </div>
</section>

<!-- ═══ Demo ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-sec rg-demo" id="demo" aria-labelledby="demo-h">
  <div class="rg-wrap rg-demo-grid">
    <div>
      <p class="rg-eyebrow rg-on-ink">See for yourself</p>
      <h2 id="demo-h">Open the demonstration. No sign-up, no password.</h2>
      <p class="rg-lead rg-on-ink">A made-up care home with staff, teams, people we support, tasks and a leaver on the way out. It resets every night. Press <em>Record leaving</em> on any member of staff and read the preview.</p>
      <?php if ($demo): ?>
      <form method="post" action="/demo/enter" class="rg-demo-form"><?= Csrf::field() ?><?= DemoEntry::fields() ?>
        <button class="rg-demo-btn rg-demo-main" type="submit" name="role" value="manager"><strong>Explore as a manager</strong><span>Record joiners and leavers, see tasks, teams and approvals</span></button>
        <button class="rg-demo-btn" type="submit" name="role" value="staff"><strong>Explore as a member of staff</strong><span>See your own profile and your team</span></button>
      </form>
      <?php else: ?><p><a class="rg-btn rg-btn-gold" href="<?= View::e($mail) ?>">Ask for a demonstration</a></p><?php endif; ?>
    </div>
    <div class="rg-demo-side">
      <h3>Prefer a conversation?</h3>
      <p>Thirty minutes, using your own questions: your last leaver, the spreadsheet of registrations, the system nobody trusts.</p>
      <p><a class="rg-btn rg-btn-line-light" href="<?= View::e($mail) ?>">Book a walkthrough</a></p>
    </div>
  </div>
</section>

<!-- ═══ Questions ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════ -->
<section class="rg-sec rg-faq" id="faq" aria-labelledby="faq-h">
  <div class="rg-wrap rg-faq-grid">
    <h2 id="faq-h">Questions we would ask.</h2>
    <div class="rg-faq-list">
      <details><summary>Is it ready for real records?</summary><p>No. It is a working demonstration. Before real data goes in you need a security review, a data protection assessment, tested backups and a trial in one organisation. We would rather build the first version with a pilot partner than sell you something half-proved.</p></details>
      <details><summary>Does it replace our HR system, rota or payroll?</summary><p>No. It is the one record of who your people are and which relationships they have, with everything else listening. Rotas, payroll and leave booking are out of scope.</p></details>
      <details><summary>Does it replace care planning?</summary><p>No. Care Core holds operational facts about the people you support. Care plans, daily notes and medication belong in Care Plans, which reads these people from here.</p></details>
      <details><summary>Can we move over from our old systems?</summary><p>Yes, as a migration: an importer checks your file with a dry run, matches existing people, never overwrites and is safe to run again. It is one-way, and meant for moving, not for staying in step.</p></details>
      <details><summary>What does it cost, and where is the data held?</summary><p>Both are agreed with pilot partners and their data protection officers.</p></details>
    </div>
  </div>
</section>

<section class="rg-close" aria-labelledby="close-h">
  <div class="rg-wrap">
    <h2 id="close-h">One record. Change it once.</h2>
    <p class="rg-cta"><?php if ($demo): ?><a class="rg-btn rg-btn-gold" href="#demo">Open the demonstration</a><?php endif; ?><a class="rg-btn rg-btn-line-light" href="<?= View::e($mail) ?>">Book a walkthrough</a></p>
  </div>
</section>

</div>
