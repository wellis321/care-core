<?php use App\Core\View; ?>
<div class="pagehead"><div><p class="eyebrow">Administration</p><h1>Admin</h1></div></div>
<div class="grid cols-3">
  <a class="card" href="/admin/users" style="text-decoration:none;color:inherit"><h2>Logins</h2><p class="muted">Who can sign in, their role, and invitations.</p></a>
  <a class="card" href="/admin/checklists" style="text-decoration:none;color:inherit"><h2>Checklists</h2><p class="muted">The onboarding, role-change and offboarding lists.</p></a>
  <a class="card" href="/admin/reconcile" style="text-decoration:none;color:inherit"><h2>Consistency checks</h2><p class="muted">Finds anything that should have ended and has not.</p></a>
  <a class="card" href="/admin/integrations" style="text-decoration:none;color:inherit"><h2>Integrations</h2><p class="muted">Webhooks out, and SCIM in from Microsoft Entra.</p></a>
  <a class="card" href="/admin/import" style="text-decoration:none;color:inherit"><h2>Import data</h2><p class="muted">Bring people, staff and teams across from the older systems.</p></a>
  <a class="card" href="/admin/signin-security" style="text-decoration:none;color:inherit"><h2>Sign-in security</h2><p class="muted">Require two-step sign-in for administrators, managers or everyone.</p></a>
  <a class="card" href="/admin/single-sign-on" style="text-decoration:none;color:inherit"><h2>Single sign-in</h2><p class="muted">Let the other products sign people in through Care Core.</p></a>
  <a class="card" href="/admin/modules" style="text-decoration:none;color:inherit"><h2>Modules</h2><p class="muted">Switch Staff, Teams and Care on or off.</p></a>
  <a class="card" href="/activity" style="text-decoration:none;color:inherit"><h2>Activity</h2><p class="muted">Who did what, and when.</p></a>
</div>
