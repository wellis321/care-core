<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $o = fn($k, $d = '') => $e($old[$k] ?? $d); ?>
<p class="crumbs"><a href="/people">People</a> › <a href="/people/<?= $e($emp['person_uuid']) ?>"><?= $e($emp['first_name'] . ' ' . $emp['last_name']) ?></a> › Change role or team</p>
<div class="pagehead"><div><p class="eyebrow">Mover</p><h1>Change role or team</h1><p class="muted">Today: <?= $e($emp['job_title']) ?>. Fill in only what is changing. The old details are kept in their history.</p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<form class="form form--wide" method="post">
  <?= Csrf::field() ?>
  <div class="formgrid">
    <div class="form-group"><label for="effective">Effective from</label><input id="effective" name="effective" type="date" required max="<?= $e(\App\Core\Clock::today()) ?>" value="<?= $o('effective') ?>"><span class="hint">Today or an earlier date.</span></div>
    <div class="form-group"><label for="job_title">New job title</label><input id="job_title" name="job_title" type="text" placeholder="<?= $e($emp['job_title']) ?>" value="<?= $o('job_title') ?>"></div>
    <div class="form-group"><label for="contract_type">Contract</label><select id="contract_type" name="contract_type"><option value="">No change</option><?php foreach ($contracts as $c): ?><option value="<?= $e($c) ?>"<?= ($old['contract_type'] ?? '') === $c ? ' selected' : '' ?>><?= $e(ucwords(str_replace('_', ' ', $c))) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="hours_per_week">Hours per week</label><input id="hours_per_week" name="hours_per_week" type="number" step="0.5" min="0.5" max="168" placeholder="<?= $e($emp['hours_per_week']) ?>" value="<?= $o('hours_per_week') ?>"></div>
    <div class="form-group"><label for="line_manager_person_id">Line manager</label><select id="line_manager_person_id" name="line_manager_person_id"><option value="">No change</option><?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (string) ($old['line_manager_person_id'] ?? '') === (string) $m['id'] ? ' selected' : '' ?>><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></div>
    <?php if (\App\Core\Modules::enabled(\App\Core\Auth::orgId(), 'teams')): ?>
    <div class="form-group"><label for="new_team_id">Move to team</label><select id="new_team_id" name="new_team_id"><option value="">No change</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (string) ($old['new_team_id'] ?? '') === (string) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select><span class="hint">They leave their current main team the day before.</span></div>
    <div class="form-group"><label for="new_team_role">Role in the new team</label><input id="new_team_role" name="new_team_role" type="text" value="<?= $o('new_team_role') ?>"></div>
    <?php endif; ?>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Record the change</button><a class="btn btn-secondary" href="/people/<?= $e($emp['person_uuid']) ?>">Cancel</a></div>
</form>
