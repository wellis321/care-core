<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $o = fn($k, $d = '') => $e($old[$k] ?? $d);
$labels = ['applied' => 'Applied', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Not taken forward', 'withdrawn' => 'Withdrawn'];
$moves = ['applied' => ['shortlisted' => 'Shortlist', 'rejected' => 'Not taking forward', 'withdrawn' => 'They withdrew'], 'shortlisted' => ['interview' => 'Invite to interview', 'offer' => 'Make an offer', 'rejected' => 'Not taking forward', 'withdrawn' => 'They withdrew'],
  'interview' => ['offer' => 'Make an offer', 'shortlisted' => 'Back to shortlist', 'rejected' => 'Not taking forward', 'withdrawn' => 'They withdrew'], 'offer' => ['interview' => 'Back to interview', 'rejected' => 'Withdraw the offer', 'withdrawn' => 'They declined'],
  'rejected' => ['applied' => 'Reopen'], 'withdrawn' => ['applied' => 'Reopen'], 'hired' => []];
$can = $moves[$a['stage']] ?? []; $hired = $a['stage'] === 'hired'; ?>
<p class="crumbs"><a href="/recruitment">Recruitment</a> › <a href="/recruitment/vacancies/<?= $e($v['uuid']) ?>"><?= $e($v['reference']) ?></a> › <?= $e($a['first_name'] . ' ' . $a['last_name']) ?></p>
<div class="pagehead"><div><p class="eyebrow"><?= $e($v['title']) ?></p><h1><?= $e($a['first_name'] . ' ' . $a['last_name']) ?> <span class="chip"><?= $e($labels[$a['stage']]) ?></span></h1>
  <?php if ($hired && $person): ?><p class="muted">Hired. Now in People: <a href="/people/<?= $e($person['uuid']) ?>"><?= $e($person['first_name'] . ' ' . $person['last_name']) ?></a></p><?php endif; ?></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<div class="split">
<div>
  <?php if ($can): ?>
  <section class="card section">
    <h2>Move them on</h2>
    <?php foreach ($can as $to => $label): ?>
    <form class="form" method="post" action="/recruitment/applications/<?= $e($a['uuid']) ?>/stage" style="margin-bottom:.8rem">
      <?= Csrf::field() ?><input type="hidden" name="stage" value="<?= $e($to) ?>">
      <div class="formgrid">
        <?php if ($to === 'interview' && $a['stage'] === 'shortlisted'): ?><div class="form-group"><label for="interview_date_<?= $e($to) ?>">Interview date</label><input id="interview_date_<?= $e($to) ?>" name="interview_date" type="date" value="<?= $e($a['interview_date'] ?? '') ?>"></div><?php endif; ?>
        <?php if ($to === 'offer'): ?><div class="form-group"><label for="proposed_<?= $e($to) ?>">Proposed start date</label><input id="proposed_<?= $e($to) ?>" name="proposed_start_date" type="date" required value="<?= $e($a['proposed_start_date'] ?? '') ?>"></div><?php endif; ?>
        <?php if (in_array($to, ['rejected', 'withdrawn'], true)): ?><div class="form-group"><label for="reason_<?= $e($to) ?>">Reason (optional)</label><input id="reason_<?= $e($to) ?>" name="reason" type="text" maxlength="500"></div><?php endif; ?>
      </div>
      <button class="btn <?= in_array($to, ['shortlisted', 'interview', 'offer'], true) && $to !== 'shortlisted' || $to === 'shortlisted' && $a['stage'] === 'applied' ? 'btn-primary' : 'btn-secondary' ?>" type="submit"><?= $e($label) ?></button>
    </form>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if ($a['stage'] === 'offer'): ?>
  <section class="card section">
    <h2>The offer</h2>
    <p>Offered <?= View::date($a['offer_date']) ?> · proposed start <?= View::date($a['proposed_start_date']) ?>.</p>
    <?php if ($a['offer_accepted_on']): ?><p><strong>Accepted <?= View::date($a['offer_accepted_on']) ?>.</strong></p>
    <?php else: ?>
    <form method="post" action="/recruitment/applications/<?= $e($a['uuid']) ?>/accept" class="form"><?= Csrf::field() ?>
      <div class="formgrid"><div class="form-group"><label for="accepted_on">Date accepted</label><input id="accepted_on" name="accepted_on" type="date" max="<?= $e($today) ?>" value="<?= $e($today) ?>"></div></div>
      <button class="btn btn-primary" type="submit">They accepted</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card section">
    <h2>Checks before they can start</h2>
    <?php if ($checks): ?>
    <ul class="plain">
      <?php foreach ($checks as $c): ?>
      <li>
        <strong><?= $e($c['title']) ?></strong> <?= $c['is_required'] ? '<span class="chip">Required</span>' : '<span class="chip">Optional</span>' ?>
        <?= $c['done_on'] ? '<span class="chip chip--staff">Done ' . View::date($c['done_on']) . '</span>' : '' ?>
        <?= $c['description'] ? '<br><span class="muted small">' . $e($c['description']) . '</span>' : '' ?><?= $c['notes'] ? '<br><span class="small">' . $e($c['notes']) . '</span>' : '' ?>
        <?php if (!$hired): ?>
        <form class="inline" method="post" action="/recruitment/applications/<?= $e($a['uuid']) ?>/check"><?= Csrf::field() ?><input type="hidden" name="check" value="<?= (int) $c['id'] ?>">
          <?php if ($c['done_on']): ?><input type="hidden" name="clear" value="1"><button class="linkbtn" type="submit">Mark as not done</button>
          <?php else: ?><input name="notes" type="text" maxlength="500" placeholder="Note (optional)" aria-label="Note for <?= $e($c['title']) ?>"> <button class="btn btn-secondary" type="submit">Done today</button><?php endif; ?></form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">This role has no checks.</p><?php endif; ?>
  </section>

  <?php if ($a['stage'] === 'offer' && $a['offer_accepted_on']): ?>
  <section class="card section">
    <h2>Hire</h2>
    <?php if ($outstanding): ?>
      <div class="alert alert-info">Still to do before they can start: <?= $e(implode('; ', $outstanding)) ?>.</div>
    <?php else: ?>
    <p class="muted">This starts them through the ordinary joiner process: their record in People, their employment, their team, an optional login and the onboarding checklist.</p>
    <?php endif; ?>
    <form class="form" method="post" action="/recruitment/applications/<?= $e($a['uuid']) ?>/hire">
      <?= Csrf::field() ?>
      <div class="formgrid">
        <div class="form-group"><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" required value="<?= $o('start_date', $a['proposed_start_date'] ?? '') ?>"></div>
        <div class="form-group"><label for="job_title">Job title</label><input id="job_title" name="job_title" type="text" value="<?= $o('job_title', $v['title']) ?>"></div>
        <div class="form-group"><label for="employee_ref">Employee reference</label><input id="employee_ref" name="employee_ref" type="text" value="<?= $o('employee_ref') ?>"></div>
        <div class="form-group"><label for="line_manager_person_id">Line manager</label><select id="line_manager_person_id" name="line_manager_person_id"><option value="">None</option><?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (string) ($old['line_manager_person_id'] ?? $v['line_manager_person_id']) === (string) $m['id'] ? ' selected' : '' ?>><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="team_id">Team</label><select id="team_id" name="team_id"><option value="">None</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (string) ($old['team_id'] ?? $v['team_id']) === (string) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="check"><input type="checkbox" name="create_login" value="1"<?= !empty($old['create_login']) ? ' checked' : '' ?>> Create a login for them</label><span class="hint">Needs an email address.</span></div>
      </div>
      <button class="btn btn-primary" type="submit"<?= $outstanding ? ' disabled' : '' ?>>Hire <?= $e($a['first_name']) ?></button>
    </form>
  </section>
  <?php endif; ?>
</div>
<aside>
  <section class="card section"><h2>Their details</h2>
  <?php if (!$hired): ?>
    <form method="post" action="/recruitment/applications/<?= $e($a['uuid']) ?>">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="a_first">First name</label><input id="a_first" name="first_name" type="text" required value="<?= $e($a['first_name']) ?>"></div>
      <div class="form-group"><label for="a_last">Last name</label><input id="a_last" name="last_name" type="text" required value="<?= $e($a['last_name']) ?>"></div>
      <div class="form-group"><label for="a_email">Email</label><input id="a_email" name="email" type="email" value="<?= $e($a['email'] ?? '') ?>"></div>
      <div class="form-group"><label for="a_phone">Phone</label><input id="a_phone" name="phone" type="text" value="<?= $e($a['phone'] ?? '') ?>"></div>
      <div class="form-group"><label for="a_dob">Date of birth</label><input id="a_dob" name="date_of_birth" type="date" value="<?= $e($a['date_of_birth'] ?? '') ?>"></div>
      <div class="form-group"><label for="a_source">Where they heard about it</label><input id="a_source" name="source" type="text" value="<?= $e($a['source'] ?? '') ?>"></div>
      <div class="form-group"><label for="a_interview">Interview notes</label><textarea id="a_interview" name="interview_notes" rows="5"><?= $e($a['interview_notes'] ?? '') ?></textarea></div>
      <div class="form-group"><label for="a_notes">Notes</label><textarea id="a_notes" name="notes" rows="3"><?= $e($a['notes'] ?? '') ?></textarea></div>
      <button class="btn btn-secondary" type="submit">Save</button>
    </form>
  <?php else: ?><p class="muted">Their details are in People now.</p><?php endif; ?>
  </section>
  <?php if (in_array($a['stage'], ['rejected', 'withdrawn'], true)): ?>
  <section class="card section"><h2>Remove their details</h2>
    <p class="muted">They are not a person here, so once you no longer need their details you can remove them. The fact that someone applied stays.</p>
    <form method="post" action="/recruitment/applications/<?= $e($a['uuid']) ?>/remove-details" onsubmit="return confirm('Remove this applicant’s personal details? This cannot be undone.');"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit">Remove personal details</button></form>
  </section>
  <?php endif; ?>
</aside>
</div>
