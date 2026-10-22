<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Modules</p>
<div class="pagehead"><div><h1>Modules</h1><p class="muted">One system, sold in parts. People is always on: it is the core that everything else builds on.</p></div></div>
<form class="form" method="post" action="/admin/modules">
  <?= Csrf::field() ?>
  <?php foreach ($modules as $m): ?>
  <div class="form-group"><label class="check"><input type="checkbox" name="m_<?= $e($m['key']) ?>" value="1"<?= $m['on'] ? ' checked' : '' ?><?= $m['locked'] ? ' disabled' : '' ?>> <?= $e($m['label']) ?><?= $m['locked'] ? ' <span class="muted small">(always on)</span>' : '' ?></label></div>
  <?php endforeach; ?>
  <button class="btn btn-primary" type="submit">Save</button>
</form>
