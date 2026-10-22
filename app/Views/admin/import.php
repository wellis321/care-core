<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Import data</p>
<div class="pagehead"><div><h1>Import data</h1><p class="muted">Bring people, staff, teams and everything attached to them across from the older Staff, People and Team services, or from any system that can produce an import file. You always see exactly what would happen before anything changes.</p></div></div>

<section class="section card">
  <h2>1. Choose the files</h2>
  <p>Import files are JSON in the <a href="/docs/import-format">Care Core import format</a>. Produce them from the old systems with the <a href="/docs/import-migrating">exporters</a>, or build them from a spreadsheet. You can upload several at once (for example staff, people and teams together): people in different files that turn out to be the same person are merged.</p>
  <form class="form" method="post" action="/admin/import/preview" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="bundles">Import files</label><input id="bundles" type="file" name="bundles[]" multiple accept=".json,application/json" required><span class="hint">Up to 6 files. For very large files use the command-line importer.</span></div>
    <div class="actions"><button class="btn btn-primary" type="submit">Check them (nothing is changed yet)</button></div>
  </form>
</section>

<section class="section">
  <h2>Earlier imports</h2>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>When</th><th>What</th><th>Kind</th><th>Outcome</th><th>Records</th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): $sum = json_decode((string) $r['summary'], true) ?: []; $n = 0; foreach (($sum['counts'] ?? []) as $c) { $n += ($c['created'] ?? 0); } ?>
      <tr>
        <td class="nowrap"><?= $e(date('j M Y H:i', strtotime($r['created_at']))) ?></td>
        <td><?= $e($r['label'] ?: '—') ?></td>
        <td><?= $r['mode'] === 'dry_run' ? 'Check only' : 'Import' ?></td>
        <td><?= $r['mode'] === 'dry_run' ? '<span class="chip">Nothing changed</span>' : ($r['status'] === 'completed' ? '<span class="chip chip--staff">Imported</span>' : '<span class="chip chip--bad">Not imported</span>') ?></td>
        <td><?= (int) $n ?> created<?= !empty($sum['warnings']) ? ' · ' . (int) $sum['warnings'] . ' warnings' : '' ?><?= !empty($sum['errors']) ? ' · ' . (int) $sum['errors'] . ' errors' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?><tr class="empty-row"><td colspan="5">Nothing has been imported yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
