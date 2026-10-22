<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Ctx;
use App\Api\Page;
use App\Api\Present;
use App\Core\Database as DB;
use App\Modules\Staff\Checklists;

final class TasksApi extends BaseApi
{
    public const TAG = 'Tasks';

    public static function routes(): array
    {
        return [
            self::route('GET', '/tasks', 'tasks.read', 'index', 'List tasks', self::paging() + [
                'status' => ['string', 'open (default), done, skipped or all.'], 'person_id' => ['uuid', 'Tasks about this person.'],
                'kind' => ['string', 'joiner, mover, leaver, reassign or manual.'], 'due_before' => ['date', 'Due on or before this date.'],
            ], [], 'list:Task'),
            self::route('GET', '/tasks/{id}', 'tasks.read', 'show', 'Get a task', [], [], 'Task'),
            self::route('PATCH', '/tasks/{id}', 'tasks.write', 'update', 'Mark a task done, skipped or open', [], ['status' => ['type' => 'enum', 'required' => true, 'enum' => ['open', 'done', 'skipped']]], 'Task', 200, 'Marking a task done raises a task.completed event.'),
        ];
    }

    public function index(Ctx $c): array
    {
        $where = ' WHERE t.organisation_id = ?';
        $params = [$c->org()];
        $status = $c->q('status', 'open');
        if ($status !== 'all') {
            if (!in_array($status, ['open', 'done', 'skipped'], true)) {
                throw ApiException::badRequest('status must be open, done, skipped or all.');
            }
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }
        if ($k = $c->q('kind')) {
            if (!in_array($k, ['joiner', 'mover', 'leaver', 'reassign', 'manual'], true)) {
                throw ApiException::badRequest('kind must be joiner, mover, leaver, reassign or manual.');
            }
            $where .= ' AND t.kind = ?';
            $params[] = $k;
        }
        if ($pid = $c->q('person_id')) {
            $where .= ' AND t.person_id = ?';
            $params[] = (int) $c->person($pid)['id'];
        }
        if ($d = $c->qDate('due_before')) {
            $where .= ' AND t.due_date <= ?';
            $params[] = $d;
        }
        return Page::run($c, 'SELECT t.*, p.uuid AS person_uuid FROM tasks t JOIN persons p ON p.id = t.person_id' . $where, $params, 't.id', [Present::class, 'task']);
    }

    public function show(Ctx $c): array
    {
        return ['data' => self::one($c, $c->params['id'])];
    }

    public function update(Ctx $c): array
    {
        $t = $c->find('tasks', $c->params['id'], 'That task');
        $in = $c->input(['status' => ['type' => 'enum', 'required' => true, 'enum' => ['open', 'done', 'skipped']]]);
        Checklists::setStatus($c->org(), $t['uuid'], $in['status'], null, $c->actor());
        return ['data' => self::one($c, $t['uuid'])];
    }

    /** @return array<string,mixed> */
    private static function one(Ctx $c, string $uuid): array
    {
        $t = $c->find('tasks', $uuid, 'That task');
        return Present::task($t + ['person_uuid' => DB::value('SELECT uuid FROM persons WHERE id = ?', [$t['person_id']])]);
    }
}
