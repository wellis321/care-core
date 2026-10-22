<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Modules\Admin\AdminController;
use App\Modules\Care\CareController;
use App\Modules\Docs\DocsController;
use App\Modules\Identity\AuthController;
use App\Modules\Identity\SsoController;
use App\Modules\People\DashboardController;
use App\Modules\People\PeopleController;
use App\Modules\Staff\SelfServiceController;
use App\Modules\Staff\StaffController;
use App\Modules\Staff\StaffRecordController;
use App\Modules\Staff\TaskController;
use App\Modules\Teams\TeamController;

// Public information and documentation (no sign-in)
$router->add('GET', '/about', [DocsController::class, 'about'], null, null, false, false);
$router->add('GET', '/features', [DocsController::class, 'features'], null, null, false, false);
$router->add('GET', '/security', [DocsController::class, 'trust'], null, null, false, false);
$router->add('GET', '/docs', [DocsController::class, 'index'], null, null, false, false);
$router->add('GET', '/docs/{slug}', [DocsController::class, 'page'], null, null, false, false);

// Public
$router->add('GET', '/login', [AuthController::class, 'loginForm'], null, null, false, false);
$router->add('GET', '/login/entra', [SsoController::class, 'start'], null, null, false, false);
$router->add('GET', '/login/entra/callback', [SsoController::class, 'callback'], null, null, false, false);
$router->add('POST', '/login', [AuthController::class, 'login'], null, null, true, false);
$router->add('POST', '/logout', [AuthController::class, 'logout'], null, null, true, false);
$router->add('GET', '/invite/{token}', [AuthController::class, 'inviteForm'], null, null, false, false);
$router->add('POST', '/invite/{token}', [AuthController::class, 'invite'], null, null, true, false);

// Your account
$router->add('GET', '/account', [AuthController::class, 'accountForm']);
$router->add('POST', '/account/password', [AuthController::class, 'changePassword']);

// Dashboard
$router->add('GET', '/', [DashboardController::class, 'index']);

// People
$router->add('GET', '/people', [PeopleController::class, 'index']);
$router->add('GET', '/people/{uuid}', [PeopleController::class, 'show']);
$router->add('GET', '/people/{uuid}/edit', [PeopleController::class, 'editForm'], 'people.edit');
$router->add('POST', '/people/{uuid}/edit', [PeopleController::class, 'edit'], 'people.edit');

// Staff lifecycle
$router->add('GET', '/staff/join', [StaffController::class, 'joinForm'], 'staff.manage', 'staff');
$router->add('POST', '/staff/join', [StaffController::class, 'join'], 'staff.manage', 'staff');
$router->add('GET', '/staff/{uuid}/move', [StaffController::class, 'moveForm'], 'staff.manage', 'staff');
$router->add('POST', '/staff/{uuid}/move', [StaffController::class, 'move'], 'staff.manage', 'staff');
$router->add('GET', '/staff/{uuid}/leave', [StaffController::class, 'leaveForm'], 'staff.manage', 'staff');
$router->add('POST', '/staff/{uuid}/leave', [StaffController::class, 'leave'], 'staff.manage', 'staff');

// Staff record (managers and HR)
$router->add('GET', '/people/{uuid}/record', [StaffRecordController::class, 'show'], 'staff.manage', 'staff');
$router->add('POST', '/people/{uuid}/record/profile', [StaffRecordController::class, 'saveProfile'], 'staff.manage', 'staff');
$router->add('POST', '/people/{uuid}/record/section', [StaffRecordController::class, 'saveSection'], 'staff.manage', 'staff');
$router->add('POST', '/people/{uuid}/record/file', [StaffRecordController::class, 'saveFile'], 'staff.manage', 'staff');
$router->add('POST', '/people/{uuid}/compliance/{kind}', [StaffRecordController::class, 'addCompliance'], 'staff.manage', 'staff');
$router->add('POST', '/people/{uuid}/compliance/{kind}/remove', [StaffRecordController::class, 'removeCompliance'], 'staff.manage', 'staff');

// Self-service, approvals and private pictures (every signed-in person)
$router->add('GET', '/me', [SelfServiceController::class, 'me']);
$router->add('POST', '/me', [SelfServiceController::class, 'saveDetails']);
$router->add('POST', '/me/photo', [SelfServiceController::class, 'savePhoto']);
$router->add('POST', '/me/signature', [SelfServiceController::class, 'saveSignature']);
$router->add('POST', '/me/changes/withdraw', [SelfServiceController::class, 'withdraw']);
$router->add('GET', '/approvals', [SelfServiceController::class, 'approvals']);
$router->add('POST', '/approvals/{uuid}', [SelfServiceController::class, 'decide']);
$router->add('GET', '/files/{kind}/{uuid}', [SelfServiceController::class, 'file']);

// Tasks
$router->add('GET', '/tasks', [TaskController::class, 'index'], 'tasks.view');
$router->add('POST', '/tasks/{uuid}', [TaskController::class, 'update'], 'tasks.view');

// Teams
$router->add('GET', '/teams', [TeamController::class, 'index'], 'teams.view', 'teams');
$router->add('POST', '/teams', [TeamController::class, 'create'], 'teams.manage', 'teams');
$router->add('GET', '/teams/{uuid}', [TeamController::class, 'show'], 'teams.view', 'teams');
$router->add('POST', '/teams/{uuid}/edit', [TeamController::class, 'edit'], 'teams.manage', 'teams');
$router->add('POST', '/teams/{uuid}/close', [TeamController::class, 'close'], 'teams.manage', 'teams');
$router->add('POST', '/teams/{uuid}/members', [TeamController::class, 'addMember'], 'teams.manage', 'teams');
$router->add('POST', '/teams/{uuid}/members/end', [TeamController::class, 'endMember'], 'teams.manage', 'teams');

// Care (people we support)
$router->add('GET', '/care/new', [CareController::class, 'newForm'], 'care.manage', 'care');
$router->add('POST', '/care/new', [CareController::class, 'create'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/start', [CareController::class, 'start'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/end', [CareController::class, 'end'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/needs', [CareController::class, 'addNeed'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/needs/end', [CareController::class, 'endNeed'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/contacts', [CareController::class, 'addContact'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/contacts/remove', [CareController::class, 'removeContact'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/keyworkers', [CareController::class, 'assignKeyWorker'], 'care.manage', 'care');
$router->add('POST', '/care/{uuid}/keyworkers/end', [CareController::class, 'endKeyWorker'], 'care.manage', 'care');

// Activity and administration
$router->add('GET', '/activity', [AdminController::class, 'activity'], 'audit.view');
$router->add('GET', '/admin', [AdminController::class, 'index'], '*');
$router->add('GET', '/admin/users', [AdminController::class, 'users'], '*');
$router->add('POST', '/admin/users', [AdminController::class, 'createUser'], '*');
$router->add('POST', '/admin/users/reinvite', [AdminController::class, 'reinviteUser'], '*');
$router->add('POST', '/admin/users/disable', [AdminController::class, 'disableUser'], '*');
$router->add('GET', '/admin/integrations', [AdminController::class, 'integrations'], '*');
$router->add('POST', '/admin/webhooks', [AdminController::class, 'addWebhook'], '*');
$router->add('POST', '/admin/webhooks/toggle', [AdminController::class, 'toggleWebhook'], '*');
$router->add('POST', '/admin/scim', [AdminController::class, 'createScimToken'], '*');
$router->add('POST', '/admin/scim/revoke', [AdminController::class, 'revokeScimToken'], '*');
$router->add('GET', '/admin/checklists', [AdminController::class, 'checklists'], '*');
$router->add('POST', '/admin/checklists/items', [AdminController::class, 'addChecklistItem'], '*');
$router->add('POST', '/admin/checklists/items/remove', [AdminController::class, 'removeChecklistItem'], '*');
$router->add('GET', '/admin/reconcile', [AdminController::class, 'reconcile'], 'reconcile.view');
$router->add('POST', '/admin/reconcile/fix', [AdminController::class, 'reconcileFix'], '*');
$router->add('GET', '/admin/modules', [AdminController::class, 'modules'], '*');
$router->add('POST', '/admin/modules', [AdminController::class, 'saveModules'], '*');
