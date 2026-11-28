<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › My work</p>
<div class="pagehead"><div><h1>My work: connected products</h1><p class="muted">When someone opens <a href="/my-work">My work</a>, Care Core asks each product here what is waiting for that person. The same connection feeds <a href="/search">Search</a>. A product answers only with short titles, dates and links, and only for what that person may see there. Nothing is stored in Care Core.</p></div></div>
<section class="section"><div class="card">
  <table class="list"><thead><tr><th>Product</th><th>Address</th><th>Last answered</th><th><span class="sr">Actions</span></th></tr></thead><tbody>
  <?php foreach ($sources as $s): ?><tr>
    <td><?= $e($s['name']) ?><br><?= $s['active'] ? '<span class="chip chip--staff">On</span>' : '<span class="chip chip--warn">Off</span>' ?></td>
    <td class="small"><?= $e($s['base_url']) ?></td>
    <td class="small"><?= $s['last_ok_at'] ? View::date($s['last_ok_at']) : 'never' ?><?= $s['last_error'] ? '<br><span class="muted">' . $e($s['last_error']) . '</span>' : '' ?></td>
    <td class="right"><div class="row-actions">
      <form method="post" action="/admin/my-work/toggle"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="on" value="<?= $s['active'] ? '0' : '1' ?>"><button class="btn btn--small"><?= $s['active'] ? 'Switch off' : 'Switch on' ?></button></form>
      <form method="post" action="/admin/my-work/remove"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn--small">Remove</button></form></div></td>
  </tr><?php endforeach; ?>
  <?php if (!$sources): ?><tr class="empty-row"><td colspan="4">No products connected yet.</td></tr><?php endif; ?>
  </tbody></table>
  <form method="post" action="/admin/my-work" class="form form--wide" style="margin-top:1.5rem"><?= Csrf::field() ?>
    <h3>Connect a product</h3>
    <div class="formgrid">
      <div class="form-group"><label for="wname">Product name</label><input id="wname" name="name" required maxlength="100" placeholder="Incident Core"></div>
      <div class="form-group"><label for="wurl">Address</label><input id="wurl" name="url" type="url" required placeholder="https://incidents.example.org"><span class="hint">Just the address of the product.</span></div>
      <div class="form-group"><label for="wkey">Key</label><input id="wkey" name="key" required autocomplete="off" placeholder="ick_…"><span class="hint">In the product, open Integrations, make a key with the “My work” and “Search” permissions and paste it here. Care Core keeps it scrambled.</span></div>
    </div>
    <button class="btn btn--primary">Connect</button>
  </form>
</div></section>
