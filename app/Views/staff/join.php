<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $o = fn($k, $d = '') => $e($old[$k] ?? $d); ?>
<p class="crumbs"><a href="/people">People</a> › <?= $person ? $e($person['first_name'] . ' ' . $person['last_name']) . ' › ' : '' ?><?= $e($title) ?></p>
<div class="pagehead"><div><p class="eyebrow">Joiner</p><h1><?= $e($title) ?></h1><p class="muted">Everything is set up together: the person, their job, their team, a login if you want one, and the onboarding checklist.</p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<form class="form form--wide" method="post">
  <?= Csrf::field() ?>
  <?php if ($person): ?>
    <input type="hidden" name="person_uuid" value="<?= $e($person['uuid']) ?>">
    <div class="alert alert-info">This is a <strong>new employment</strong> for <strong><?= $e($person['first_name'] . ' ' . $person['last_name']) ?></strong>. Their earlier employment stays exactly as it was.</div>
  <?php else: ?>
  <h2>About them</h2>
  <div class="formgrid">
    <div class="form-group"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" required value="<?= $o('first_name') ?>"></div>
    <div class="form-group"><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" required value="<?= $o('last_name') ?>"></div>
    <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= $o('email') ?>"><span class="hint">Needed if they will have a login.</span></div>
    <div class="form-group"><label for="phone">Phone</label><input id="phone" name="phone" type="text" value="<?= $o('phone') ?>"></div>
    <div class="form-group"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="<?= $o('date_of_birth') ?>"></div>
    <div class="form-group"><label for="preferred_name">Known as</label><input id="preferred_name" name="preferred_name" type="text" value="<?= $o('preferred_name') ?>"></div>
  </div>
  <?php endif; ?>
  <h2>The job</h2>
  <div class="formgrid">
    <div class="form-group"><label for="job_title">Job title</label><input id="job_title" name="job_title" type="text" required value="<?= $o('job_title') ?>"></div>
    <div class="form-group"><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" required value="<?= $o('start_date') ?>"></div>
    <div class="form-group"><label for="contract_type">Contract</label><select id="contract_type" name="contract_type"><?php foreach ($contracts as $c): ?><option value="<?= $e($c) ?>"<?= ($old['contract_type'] ?? 'permanent') === $c ? ' selected' : '' ?>><?= $e(ucwords(str_replace('_', ' ', $c))) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="hours_per_week">Hours per week</label><input id="hours_per_week" name="hours_per_week" type="number" step="0.5" min="0.5" max="168" value="<?= $o('hours_per_week') ?>"></div>
    <div class="form-group"><label for="employee_ref">Employee reference</label><input id="employee_ref" name="employee_ref" type="text" value="<?= $o('employee_ref') ?>"></div>
    <div class="form-group"><label for="line_manager_person_id">Line manager</label><select id="line_manager_person_id" name="line_manager_person_id"><option value="">None</option><?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (string) ($old['line_manager_person_id'] ?? '') === (string) $m['id'] ? ' selected' : '' ?>><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></div>
    <?php if (\App\Core\Modules::enabled(\App\Core\Auth::orgId(), 'teams')): ?>
    <div class="form-group"><label for="team_id">Team</label><select id="team_id" name="team_id"><option value="">No team yet</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (string) ($old['team_id'] ?? '') === (string) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="team_role">Role in the team</label><input id="team_role" name="team_role" type="text" value="<?= $o('team_role') ?>"></div>
    <?php endif; ?>
  </div>
  <h2>Access</h2>
  <div class="form-group"><label class="check"><input type="checkbox" name="create_login" value="1"<?= !empty($old['create_login']) ? ' checked' : '' ?>> Give them a login (they choose their own password from an invitation link)</label></div>
  <div class="form-group"><label for="login_role">Their role in Care Core</label><select id="login_role" name="login_role"><option value="staff">Member of staff: sees colleagues and their own tasks</option><option value="manager"<?= ($old['login_role'] ?? '') === 'manager' ? ' selected' : '' ?>>Manager: manages people, teams and care</option><option value="admin"<?= ($old['login_role'] ?? '') === 'admin' ? ' selected' : '' ?>>Administrator: everything</option></select></div>
  <div class="actions"><button class="btn btn-primary" type="submit">Start onboarding</button><a class="btn btn-secondary" href="/people">Cancel</a></div>
</form>
