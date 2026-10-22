<?php
use App\Core\Csrf;
use App\Core\View;
use App\Modules\Import\Bundle;
$e = [View::class, 'e'];
$entities = ['teams' => 'Teams', 'people' => 'People', 'profiles' => 'Personal profiles filled', 'employments' => 'Employments', 'employment_details' => 'Employment terms', 'employment_changes' => 'Role history', 'line_managers' => 'Line managers',
    'registrations' => 'Registrations', 'supervisions' => 'Supervisions', 'appraisals' => 'Appraisals', 'learning_records' => 'Learning', 'support_episodes' => 'Support episodes', 'needs' => 'Care needs', 'contacts' => 'Contacts',
    'team_places' => 'Team places', 'key_workers' => 'Key workers', 'files' => 'Photos and signatures'];
$cols = ['created' => 'Created', 'set' => 'Set', 'filled' => 'Filled', 'matched' => 'Matched existing', 'already_imported' => 'Already imported', 'duplicate' => 'Already there', 'skipped' => 'Skipped', 'failed' => 'Failed', 'not_requested' => 'Not requested'];
$c = $report['counts'];
$errors = $report['errors'];
$imported = $report['imported'];
$in = Bundle::counts($bundle);
?>
<p class="crumbs"><a href="/admin">Admin</a> › <a href="/admin/import">Import data</a> › <?= $done ? 'Result' : 'Check' ?></p>
<div class="pagehead"><div><p class="eyebrow"><?= $e($label) ?></p><h1><?= $done ? ($imported ? 'Imported' : 'Not imported') : 'What would happen' ?></h1></div></div>

<?php if ($done && $imported): ?>
  <div class="alert alert-success" role="status">The import is done<?= $errors ? ' (records that could not be imported were skipped, as you asked)' : '' ?>. Webhooks were not sent for imported history. Check <a href="/admin/reconcile">Consistency checks</a> and the <a href="/people">People</a> list.</div>
<?php elseif ($done): ?>
  <div class="alert alert-error" role="alert">Nothing was imported<?= $errors ? ': some records could not be imported and “only the records that are fine” was not chosen' : '' ?>. Your data is unchanged.</div>
<?php elseif (!$errors): ?>
  <div class="alert alert-success" role="status">Everything checked out. <strong>Nothing has been changed yet.</strong> Review the numbers and warnings below, then import.</div>
<?php else: ?>
  <div class="alert alert-error" role="alert"><strong><?= count($errors) ?> record<?= count($errors) === 1 ? '' : 's' ?> cannot be imported.</strong> Nothing has been changed yet. You can fix the source and check again, or import everything that is fine and leave these out.</div>
<?php endif; ?>

<?php foreach ($bundle['notes'] ?? [] as $n): ?><div class="alert alert-info" role="note"><?= $e($n) ?></div><?php endforeach; ?>

<section class="section">
  <h2>In the files</h2>
  <p class="muted"><?= (int) $in['people'] ?> people, <?= (int) $in['teams'] ?> teams, <?= (int) $in['employments'] ?> employments, <?= (int) $in['support_episodes'] ?> support episodes, <?= (int) $in['team_places'] ?> team places, <?= (int) $in['key_workers'] ?> key worker links, <?= (int) ($in['registrations'] + $in['supervisions'] + $in['appraisals'] + $in['learning']) ?> compliance records.</p>
</section>

<section class="section">
  <h2><?= $done ? 'What was done' : 'What would be done' ?></h2>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Kind</th><?php foreach ($cols as $label2): ?><th class="right"><?= $e($label2) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($entities as $key => $name): if (empty($c[$key])) { continue; } ?>
      <tr><td><?= $e($name) ?></td><?php foreach ($cols as $k => $_): ?><td class="right"><?= ($c[$key][$k] ?? 0) ?: '<span class="muted">·</span>' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$c): ?><tr class="empty-row"><td colspan="<?= count($cols) + 1 ?>">Nothing.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted small"><strong>Matched existing</strong>: the person was already in Care Core (same email, or same name and date of birth), so their details were added to rather than duplicated. <strong>Already imported</strong>: brought in by an earlier import. <strong>Skipped</strong>: left out, with the reason listed below.</p>
</section>

<?php if ($errors): ?>
<section class="section">
  <h2>Records that cannot be imported</h2>
  <div class="tablewrap"><table class="list"><thead><tr><th>Record</th><th>Why</th></tr></thead><tbody>
  <?php foreach ($errors as $x): ?><tr><td class="nowrap"><code><?= $e($x['ref']) ?></code></td><td><?= $e($x['message']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($report['warnings']): ?>
<section class="section">
  <h2>Warnings: something was left out or adjusted (<?= count($report['warnings']) ?>)</h2>
  <p class="muted">These records were imported, but a value was dropped or changed to keep the data valid. Fix them in Care Core afterwards if they matter.</p>
  <div class="tablewrap"><table class="list"><thead><tr><th>Record</th><th>What happened</th></tr></thead><tbody>
  <?php foreach ($report['warnings'] as $x): ?><tr><td class="nowrap"><code><?= $e($x['ref']) ?></code></td><td><?= $e($x['message']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if (!$done): ?>
<section class="section card">
  <h2>Do it</h2>
  <form class="form" method="post" action="/admin/import/commit">
    <?= Csrf::field() ?><input type="hidden" name="token" value="<?= $e($token) ?>">
    <?php if ($errors): ?>
      <div class="form-group"><label class="check"><input type="checkbox" name="skip_errors" value="1"> Import the records that are fine, and leave out the <?= count($errors) ?> that cannot be imported</label></div>
    <?php endif; ?>
    <p class="muted small">Imported history does not send webhooks. Passwords and logins are not imported: people sign in with Microsoft, or you create their logins afterwards. Importing the same files again is safe: nothing is duplicated.</p>
    <div class="actions"><button class="btn btn-primary" type="submit">Import for real</button><a class="btn btn-secondary" href="/admin/import">Cancel</a></div>
  </form>
</section>
<?php else: ?>
<p class="actions"><a class="btn btn-primary" href="/people">Open People</a> <a class="btn btn-secondary" href="/admin/import">Back to Import</a></p>
<?php endif; ?>
