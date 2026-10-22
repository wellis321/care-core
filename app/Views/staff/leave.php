<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $reasons = ['Resigned', 'Retired', 'Dismissed', 'End of contract', 'Redundancy', 'Moved to another service', 'Other']; ?>
<p class="crumbs"><a href="/people">People</a> › <a href="/people/<?= $e($emp['person_uuid']) ?>"><?= $e($emp['first_name'] . ' ' . $emp['last_name']) ?></a> › Record leaving</p>
<div class="pagehead"><div><p class="eyebrow">Leaver</p><h1>Record leaving: <?= $e($emp['first_name'] . ' ' . $emp['last_name']) ?></h1><p class="muted"><?= $e($emp['job_title']) ?>, started <?= View::date($emp['start_date']) ?></p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>

<form class="inlineform" method="get" style="margin-bottom:1.5rem">
  <div class="form-group"><label for="date">Last day of work</label><input id="date" name="date" type="date" required value="<?= $e($date) ?>"></div>
  <button class="btn btn-secondary" type="submit">Show what will happen</button>
</form>

<?php if ($preview): ?>
<section aria-label="What will happen">
  <h2>What happens on <?= View::date($date) ?></h2>
  <div class="grid cols-2">
    <div class="impact<?= $preview['teams'] ? '' : ' impact--none' ?>"><h3>Team places</h3><?php if ($preview['teams']): ?><ul><?php foreach ($preview['teams'] as $t): ?><li><?= $e($t) ?></li><?php endforeach; ?></ul><span class="small muted">They leave on their last day. The history stays.</span><?php else: ?>None.<?php endif; ?></div>
    <div class="impact<?= $preview['keyworkers'] ? '' : ' impact--none' ?>"><h3>Key worker for</h3><?php if ($preview['keyworkers']): ?><ul><?php foreach ($preview['keyworkers'] as $t): ?><li><?= $e($t) ?></li><?php endforeach; ?></ul><span class="small muted">The links end, and a task is created to find each person a new key worker.</span><?php else: ?>Nobody.<?php endif; ?></div>
    <div class="impact<?= $preview['reports'] ? '' : ' impact--none' ?>"><h3>People who report to them</h3><?php if ($preview['reports']): ?><ul><?php foreach ($preview['reports'] as $t): ?><li><?= $e($t) ?></li><?php endforeach; ?></ul><span class="small muted">A task is created to give each a new line manager.</span><?php else: ?>Nobody.<?php endif; ?></div>
    <div class="impact<?= $preview['logins'] ? '' : ' impact--none' ?>"><h3>Logins</h3><?php if ($preview['logins']): ?><ul><?php foreach ($preview['logins'] as $t): ?><li><?= $e($t) ?></li><?php endforeach; ?></ul><span class="small muted">They stop working after their last day. Nothing needs to run.</span><?php else: ?>No login to end.<?php endif; ?></div>
  </div>
  <p class="muted"><?= (int) $preview['tasks'] ?> offboarding task<?= $preview['tasks'] === 1 ? '' : 's' ?> will be created from your leaver checklist.</p>

  <form class="form" method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="date" value="<?= $e($date) ?>">
    <div class="form-group"><label for="reason">Reason</label><select id="reason" name="reason"><option value="">Not recorded</option><?php foreach ($reasons as $r): ?><option<?= $reason === $r ? ' selected' : '' ?>><?= $e($r) ?></option><?php endforeach; ?></select></div>
    <div class="actions"><button class="btn btn-danger" type="submit">Record leaving on <?= View::date($date) ?></button><a class="btn btn-secondary" href="/people/<?= $e($emp['person_uuid']) ?>">Cancel</a></div>
  </form>
</section>
<?php endif; ?>
