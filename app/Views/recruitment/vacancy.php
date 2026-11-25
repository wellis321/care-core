<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e'];
$editable = in_array($v['status'], ['draft', 'open'], true);
$labels = ['applied' => 'Applied', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Not taken forward', 'withdrawn' => 'Withdrawn'];
$by = []; foreach ($applications as $a) { $by[$a['stage']][] = $a; } ?>
<p class="crumbs"><a href="/recruitment">Recruitment</a> › <?= $e($v['reference']) ?></p>
<div class="pagehead"><div><p class="eyebrow"><?= $e($v['reference']) ?> · <?= $e(ucfirst($v['status'])) ?></p><h1><?= $e($v['title']) ?></h1>
  <p class="muted">Role: <a href="/recruitment/roles/<?= $e($role['uuid']) ?>"><?= $e($role['title']) ?></a> · <?= (int) $hired ?> of <?= (int) $v['posts'] ?> post<?= (int) $v['posts'] === 1 ? '' : 's' ?> filled<?= $v['closing_date'] ? ' · closes ' . View::date($v['closing_date']) : '' ?></p></div>
  <div class="actions">
    <?php if ($v['status'] === 'draft' || $v['status'] === 'closed'): ?><form method="post" action="/recruitment/vacancies/<?= $e($v['uuid']) ?>/open"><?= Csrf::field() ?><button class="btn btn-primary" type="submit"><?= $v['status'] === 'closed' ? 'Reopen' : 'Open for candidates' ?></button></form><?php endif; ?>
  </div></div>
<div class="split">
<div>
  <?php foreach (['applied', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'] as $st): if (empty($by[$st])) { continue; } ?>
  <section class="card section">
    <h2><?= $e($labels[$st]) ?> <span class="muted small">(<?= count($by[$st]) ?>)</span></h2>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>Candidate</th><th>Since</th><th>Required checks</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($by[$st] as $a): ?>
        <tr>
          <td><a href="/recruitment/applications/<?= $e($a['uuid']) ?>"><?= $e($a['first_name'] . ' ' . $a['last_name']) ?></a></td>
          <td><?= View::date($a['stage_changed_on']) ?></td>
          <td><?= (int) $a['checks_required'] ? (int) $a['checks_done'] . ' of ' . (int) $a['checks_required'] : '—' ?></td>
          <td class="muted small"><?php if ($st === 'interview' && $a['interview_date']): ?>Interview <?= View::date($a['interview_date']) ?><?php elseif ($st === 'offer'): ?><?= $a['offer_accepted_on'] ? 'Accepted ' . View::date($a['offer_accepted_on']) : 'Offered, not yet accepted' ?><?php elseif ($a['reason']): ?><?= $e($a['reason']) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endforeach; ?>
  <?php if (!$applications): ?><section class="card section"><p class="muted"><?= $v['status'] === 'open' ? 'No candidates yet. Add the first one.' : 'No candidates. Open the vacancy to add some.' ?></p></section><?php endif; ?>

  <?php if ($v['status'] === 'open'): ?>
  <section class="card section">
    <h2>Add a candidate</h2>
    <form class="form" method="post" action="/recruitment/vacancies/<?= $e($v['uuid']) ?>/candidates">
      <?= Csrf::field() ?>
      <div class="formgrid">
        <div class="form-group"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" required></div>
        <div class="form-group"><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" required></div>
        <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email"></div>
        <div class="form-group"><label for="phone">Phone</label><input id="phone" name="phone" type="text"></div>
        <div class="form-group"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date"></div>
        <div class="form-group"><label for="source">Where they heard about it</label><input id="source" name="source" type="text"></div>
      </div>
      <button class="btn btn-primary" type="submit">Add candidate</button>
    </form>
  </section>
  <?php endif; ?>
</div>
<aside>
  <?php if ($editable): ?>
  <section class="card section"><h2>Vacancy details</h2>
    <form method="post" action="/recruitment/vacancies/<?= $e($v['uuid']) ?>">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="v_title">Title</label><input id="v_title" name="title" type="text" value="<?= $e($v['title']) ?>"></div>
      <div class="form-group"><label for="v_posts">Posts</label><input id="v_posts" name="posts" type="number" min="<?= max(1, (int) $hired) ?>" max="500" value="<?= (int) $v['posts'] ?>"></div>
      <div class="form-group"><label for="v_team">Team</label><select id="v_team" name="team_id"><option value="">None</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $v['team_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="v_mgr">Reports to</label><select id="v_mgr" name="line_manager_person_id"><option value="">Not decided</option><?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) $v['line_manager_person_id'] === (int) $m['id'] ? ' selected' : '' ?>><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="v_closing">Closing date</label><input id="v_closing" name="closing_date" type="date" value="<?= $e($v['closing_date'] ?? '') ?>"></div>
      <button class="btn btn-secondary" type="submit">Save</button>
    </form>
  </section>
  <?php else: ?>
  <section class="card section"><h2>Vacancy details</h2><dl class="facts"><dt>Team</dt><dd><?= $e(\App\Core\Database::value('SELECT name FROM teams WHERE id = ?', [$v['team_id']]) ?? '') ?: '—' ?></dd><dt>Closed</dt><dd><?= View::date($v['closed_on']) ?></dd></dl></section>
  <?php endif; ?>
  <?php if ($editable): ?>
  <section class="card section"><h2>Careers page</h2>
    <form method="post" action="/recruitment/vacancies/<?= $e($v['uuid']) ?>/careers">
      <?= Csrf::field() ?>
      <div class="form-group"><label class="check"><input type="checkbox" name="advertise" value="1"<?= (int) $v['advertise'] ? ' checked' : '' ?>> Show on our careers page</label>
        <span class="hint">Only while the vacancy is open and before its closing date. <?php if ($v['status'] === 'open' && (int) $v['advertise']): ?><a href="/careers/<?= $e($org['slug']) ?>/<?= $e($v['uuid']) ?>">See it</a> · Careers page: <a href="/careers/<?= $e($org['slug']) ?>">/careers/<?= $e($org['slug']) ?></a><?php endif; ?></span></div>
      <div class="form-group"><label for="v_loc">Where the job is</label><input id="v_loc" name="location_text" type="text" maxlength="150" value="<?= $e($v['location_text'] ?? '') ?>"></div>
      <div class="form-group"><label for="v_sal">Pay</label><input id="v_sal" name="salary_text" type="text" maxlength="100" value="<?= $e($v['salary_text'] ?? '') ?>" placeholder="For example £12.60 an hour"></div>
      <div class="form-group"><label for="v_sum">About the job</label><textarea id="v_sum" name="public_summary" rows="5"><?= $e($v['public_summary'] ?? '') ?></textarea><span class="hint">Shown with the role’s description and person specification.</span></div>
      <button class="btn btn-secondary" type="submit">Save</button>
    </form>
  </section>
  <?php endif; ?>
  <?php if ($editable): ?>
  <section class="card section"><h2>Close this vacancy</h2>
    <form method="post" action="/recruitment/vacancies/<?= $e($v['uuid']) ?>/close">
      <?= Csrf::field() ?>
      <div class="form-group"><label class="check"><input type="checkbox" name="reject_remaining" value="1"> Also close candidates still in progress</label></div>
      <button class="btn btn-secondary" type="submit">Close without filling</button>
    </form>
  </section>
  <?php endif; ?>
</aside>
</div>
