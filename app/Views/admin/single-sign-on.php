<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Single sign-in</p>
<div class="pagehead"><div><h1>Single sign-in</h1><p class="muted">People sign in once, here, with their password and second step. The other products trust Care Core to say who they are. Each product still decides what each person may do in it.</p></div></div>

<section class="section">
  <div class="section__head"><h2>Products</h2></div>
  <div class="card">
    <table class="list"><thead><tr><th>Product</th><th>Client ID</th><th>Redirect addresses</th><th>Last used</th><th><span class="sr">Actions</span></th></tr></thead><tbody>
      <?php foreach ($clients as $c): ?><tr>
        <td><?= $e($c['name']) ?><br><?= $c['active'] ? '<span class="chip chip--staff">On</span>' : '<span class="chip chip--warn">Off</span>' ?></td>
        <td><code><?= $e($c['client_id']) ?></code></td>
        <td class="small"><?= nl2br($e($c['redirect_uris'])) ?></td>
        <td class="nowrap"><?= View::date($c['last_used_at'] ? substr($c['last_used_at'], 0, 10) : null, 'never') ?></td>
        <td class="right"><form method="post" action="/admin/single-sign-on/toggle" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="on" value="<?= $c['active'] ? '0' : '1' ?>"><button class="btn btn--small"><?= $c['active'] ? 'Switch off' : 'Switch on' ?></button></form>
          <form method="post" action="/admin/single-sign-on/secret" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn--small">New secret</button></form></td>
      </tr><?php endforeach; ?>
      <?php if (!$clients): ?><tr class="empty-row"><td colspan="5">No products registered yet.</td></tr><?php endif; ?>
    </tbody></table>
    <form method="post" action="/admin/single-sign-on" class="form form--wide" style="margin-top:1.5rem">
      <?= Csrf::field() ?>
      <h3>Register a product</h3>
      <div class="formgrid">
        <div class="form-group"><label for="cname">Product name</label><input id="cname" name="name" type="text" required maxlength="100" placeholder="Incident Core"></div>
        <div class="form-group"><label for="curis">Redirect address</label><textarea id="curis" name="redirect_uris" rows="2" required placeholder="https://incidents.example.org/login/care-core/callback"></textarea><span class="hint">Where the product receives people after they sign in. It must match exactly. One per line.</span></div>
      </div>
      <button class="btn btn--primary">Register</button>
    </form>
  </div>
</section>

<section class="section">
  <div class="section__head"><h2>What to put in the product</h2></div>
  <div class="card">
    <p>Address of Care Core (the issuer): <span class="secret"><?= $e($issuer) ?></span></p>
    <p class="muted small">Settings are read from <code><?= $e($issuer) ?>/.well-known/openid-configuration</code>. Use the authorisation code flow with PKCE (S256). Tokens are signed RS256 and last five minutes. A person who has left, or whose login is switched off, cannot get a token.</p>
  </div>
</section>
