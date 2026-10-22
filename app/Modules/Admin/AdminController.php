<?php
declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Check;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Modules;
use App\Core\Request;
use App\Core\Response;
use App\Core\UrlGuard;
use App\Modules\Identity\UserRepo;
use App\Modules\Staff\Reconcile;

final class AdminController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        return $this->view('admin/index', ['title' => 'Admin']);
    }

    // ── Activity ────────────────────────────────────────────────────────────

    public function activity(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $page = max(1, (int) $r->input('page', '1'));
        $rows = DB::all('SELECT * FROM audit_log WHERE organisation_id = ? ORDER BY id DESC LIMIT 51 OFFSET ' . (($page - 1) * 50), [$org]);
        return $this->view('admin/activity', ['title' => 'Activity', 'rows' => array_slice($rows, 0, 50), 'page' => $page, 'more' => count($rows) > 50]);
    }

    // ── Logins ──────────────────────────────────────────────────────────────

    public function users(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        return $this->view('admin/users', ['title' => 'Logins', 'users' => UserRepo::forOrg($org), 'people' => DB::all('SELECT id, first_name, last_name FROM persons WHERE organisation_id = ? ORDER BY last_name, first_name LIMIT 500', [$org])]);
    }

    public function createUser(Request $r, array $p = []): Response
    {
        try {
            $personId = $r->input('person_id') !== '' ? (int) $r->input('person_id') : null;
            $res = UserRepo::create($this->orgId(), $personId, $r->input('email'), $r->input('role'), $this->actor(), true);
            $this->ok('Login created. Invitation link (shown once, valid for 7 days): ' . \App\Core\Config::appUrl() . '/invite/' . $res['invite_token']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/users');
    }

    public function reinviteUser(Request $r, array $p = []): Response
    {
        try {
            $token = UserRepo::reinvite($this->orgId(), (int) $r->input('id'), $this->actor());
            $this->ok('New invitation (shown once, valid for 7 days, their old password no longer works): ' . \App\Core\Config::appUrl() . '/invite/' . $token);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/users');
    }

    public function disableUser(Request $r, array $p = []): Response
    {
        try {
            if ((int) $r->input('id') === (int) Auth::user()['id']) {
                throw new DomainException('You cannot disable your own login.');
            }
            UserRepo::disable($this->orgId(), (int) $r->input('id'), $this->actor());
            $this->ok('Login disabled.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/users');
    }

    // ── Integrations: webhooks and SCIM ─────────────────────────────────────

    public function integrations(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        return $this->view('admin/integrations', [
            'title' => 'Integrations',
            'webhooks' => DB::all('SELECT w.*, (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = w.id AND d.status = \'delivered\') AS delivered, (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = w.id AND d.status <> \'delivered\') AS undelivered FROM webhook_endpoints w WHERE w.organisation_id = ? ORDER BY w.id', [$org]),
            'tokens' => DB::all('SELECT id, name, active, last_used_at, created_at FROM scim_tokens WHERE organisation_id = ? ORDER BY id', [$org]),
            'events' => DB::all('SELECT type, occurred_at, fanned_out_at FROM outbox_events WHERE organisation_id = ? ORDER BY id DESC LIMIT 15', [$org]),
            'apiKeys' => \App\Api\ApiKeys::forOrg($org),
            'scimUrl' => \App\Core\Config::appUrl() . '/scim/v2',
        ]);
    }

    public function addWebhook(Request $r, array $p = []): Response
    {
        $url = $r->input('url');
        $types = $r->input('event_types') ?: '*';
        if ($m = Check::first([UrlGuard::problem($url), Check::maxLen($types, 500, 'Event types')])) {
            $this->fail($m);
            return $this->redirect('/admin/integrations');
        }
        $secret = bin2hex(random_bytes(20));
        DB::transaction(function () use ($url, $types, $secret) {
            DB::insert('webhook_endpoints', ['organisation_id' => $this->orgId(), 'url' => $url, 'secret' => $secret, 'event_types' => $types]);
            Audit::record($this->orgId(), $this->actor(), 'webhook.created', 'webhook', null, 'Added a webhook to ' . $url);
        });
        $this->ok('Webhook added. The signing secret (shown once) is: ' . $secret);
        return $this->redirect('/admin/integrations');
    }

    public function toggleWebhook(Request $r, array $p = []): Response
    {
        DB::run('UPDATE webhook_endpoints SET active = 1 - active WHERE id = ? AND organisation_id = ?', [(int) $r->input('id'), $this->orgId()]);
        return $this->redirect('/admin/integrations');
    }

    public function createScimToken(Request $r, array $p = []): Response
    {
        $name = $r->input('name');
        if ($m = Check::first([Check::required($name, 'Name'), Check::maxLen($name, 100, 'Name')])) {
            $this->fail($m);
            return $this->redirect('/admin/integrations');
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($name, $token) {
            DB::insert('scim_tokens', ['organisation_id' => $this->orgId(), 'name' => $name, 'token_hash' => hash('sha256', $token)]);
            Audit::record($this->orgId(), $this->actor(), 'scim.token_created', 'scim', null, 'Created the SCIM token "' . $name . '"');
        });
        $this->ok('SCIM token created. Copy it now, it is not shown again: ' . $token);
        return $this->redirect('/admin/integrations');
    }

    public function revokeScimToken(Request $r, array $p = []): Response
    {
        DB::run('UPDATE scim_tokens SET active = 0 WHERE id = ? AND organisation_id = ?', [(int) $r->input('id'), $this->orgId()]);
        $this->ok('Token revoked.');
        return $this->redirect('/admin/integrations');
    }

    // ── Checklists ──────────────────────────────────────────────────────────

    public function createApiKey(Request $r, array $p = []): Response
    {
        try {
            $scopes = array_keys(array_filter((array) ($r->post['scopes'] ?? []), static fn($v) => $v === '1' || $v === 'on'));
            $res = \App\Api\ApiKeys::create($this->orgId(), $r->input('name'), $scopes, $r->input('allowed_ips'), $r->input('expires_on') ?: null, (int) ($r->input('rate_limit') ?: 120), (int) Auth::user()['id'], $this->actor());
            $this->ok('API key created. Copy it now: it is not shown again. ' . $res['token']);
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/integrations#api');
    }

    public function revokeApiKey(Request $r, array $p = []): Response
    {
        try {
            \App\Api\ApiKeys::revoke($this->orgId(), (int) $r->input('id'), $this->actor());
            $this->ok('API key revoked. It stops working immediately.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/admin/integrations#api');
    }

    public function checklists(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $tpls = DB::all('SELECT * FROM checklist_templates WHERE organisation_id = ? ORDER BY FIELD(kind, \'joiner\', \'mover\', \'leaver\'), id', [$org]);
        foreach ($tpls as &$t) {
            $t['items'] = DB::all('SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, id', [$t['id']]);
        }
        return $this->view('admin/checklists', ['title' => 'Checklists', 'templates' => $tpls]);
    }

    public function addChecklistItem(Request $r, array $p = []): Response
    {
        $tpl = DB::one('SELECT id FROM checklist_templates WHERE id = ? AND organisation_id = ?', [(int) $r->input('template_id'), $this->orgId()]);
        $title = $r->input('title');
        if (!$tpl || ($m = Check::first([Check::required($title, 'Title'), Check::maxLen($title, 200, 'Title'), Check::oneOf($r->input('owner_role'), ['admin', 'manager', 'staff'], 'who does it')]))) {
            $this->fail($m ?? 'Checklist not found.');
            return $this->redirect('/admin/checklists');
        }
        DB::insert('checklist_template_items', ['template_id' => $tpl['id'], 'title' => $title, 'owner_role' => $r->input('owner_role'), 'due_offset_days' => (int) $r->input('due_offset_days', '0'), 'sort_order' => 100]);
        $this->ok('Item added. It applies to the next person who joins, moves or leaves.');
        return $this->redirect('/admin/checklists');
    }

    public function removeChecklistItem(Request $r, array $p = []): Response
    {
        DB::run('DELETE i FROM checklist_template_items i JOIN checklist_templates t ON t.id = i.template_id WHERE i.id = ? AND t.organisation_id = ?', [(int) $r->input('id'), $this->orgId()]);
        $this->ok('Item removed.');
        return $this->redirect('/admin/checklists');
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    public function reconcile(Request $r, array $p = []): Response
    {
        return $this->view('admin/reconcile', ['title' => 'Consistency checks', 'issues' => Reconcile::check($this->orgId()), 'canFix' => Auth::can('*')]);
    }

    public function reconcileFix(Request $r, array $p = []): Response
    {
        $res = Reconcile::fix($this->orgId(), $this->actor());
        $this->ok($res['fixed'] > 0 ? 'Put right ' . $res['fixed'] . ' item(s).' : 'Nothing needed fixing.');
        return $this->redirect('/admin/reconcile');
    }

    // ── Modules ─────────────────────────────────────────────────────────────

    public function modules(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $rows = [];
        foreach (Modules::ALL as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'on' => Modules::enabled($org, $key), 'locked' => $key === 'people'];
        }
        return $this->view('admin/modules', ['title' => 'Modules', 'modules' => $rows]);
    }

    public function saveModules(Request $r, array $p = []): Response
    {
        foreach (Modules::ALL as $key => $label) {
            if ($key !== 'people') {
                Modules::set($this->orgId(), $key, $r->has('m_' . $key));
            }
        }
        Audit::record($this->orgId(), $this->actor(), 'modules.changed', 'organisation', null, 'Changed which modules are switched on');
        $this->ok('Saved.');
        return $this->redirect('/admin/modules');
    }
}
