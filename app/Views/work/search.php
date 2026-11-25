<?php use App\Core\View; $e = [View::class, 'e']; $none = $q !== '' && mb_strlen($q) >= 2 && !$own && !array_filter($products, fn($p) => $p['results']); ?>
<div class="pagehead"><div><p class="eyebrow">Find</p><h1>Search</h1><p class="muted">One search across Care Core and every product your organisation has connected. You only find what you are allowed to see in each one.</p></div></div>
<form method="get" action="/search" class="form form--wide" role="search">
  <div class="form-group"><label for="q">Search for a person, a member of staff, a team, an incident, a policy or a plan</label>
    <input id="q" name="q" type="search" value="<?= $e($q) ?>" minlength="2" maxlength="100" autofocus autocomplete="off"></div>
  <button class="btn btn--primary" type="submit">Search</button>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?><p class="muted">Type at least two letters.</p><?php endif; ?>
<?php if ($none): ?><div class="card"><p>Nothing found for “<?= $e($q) ?>”.</p></div><?php endif; ?>

<?php if ($own): ?>
<section class="section"><div class="section__head"><h2>Care Core</h2></div>
  <div class="card"><ul class="plain">
  <?php foreach ($own as $it): ?>
    <li><a href="<?= $e($it['url']) ?>"><?= $e($it['title']) ?></a> <span class="chip"><?= $e($it['kind']) ?></span><?php if ($it['detail']): ?> <span class="muted small"><?= $e($it['detail']) ?></span><?php endif; ?></li>
  <?php endforeach; ?></ul></div></section>
<?php endif; ?>

<?php foreach ($products as $pr): if (!$pr['results'] && !$pr['error']) continue; ?>
<section class="section"><div class="section__head"><h2><?= $e($pr['name']) ?></h2></div>
  <div class="card">
  <?php if ($pr['error']): ?><p class="muted"><?= $e($pr['error']) ?> <a href="<?= $e($pr['url']) ?>">Open <?= $e($pr['name']) ?></a> to search there.</p>
  <?php else: ?><ul class="plain">
    <?php foreach ($pr['results'] as $it): ?>
      <li><?= $it['url'] ? '<a href="' . $e($it['url']) . '">' . $e($it['title']) . '</a>' : $e($it['title']) ?><?php if ($it['kind']): ?> <span class="chip"><?= $e($it['kind']) ?></span><?php endif; ?><?php if ($it['detail']): ?> <span class="muted small"><?= $e($it['detail']) ?></span><?php endif; ?></li>
    <?php endforeach; ?></ul>
  <?php endif; ?></div></section>
<?php endforeach; ?>
