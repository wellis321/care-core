<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/** Onboarding and offboarding checklists: templates per organisation, tasks created from them when someone joins, moves or leaves. */
final class Checklists
{
    /** @return int tasks created */
    public static function createTasks(int $orgId, string $kind, int $personId, ?int $employmentId, string $baseDate): int
    {
        $items = DB::all(
            "SELECT i.* FROM checklist_template_items i JOIN checklist_templates t ON t.id = i.template_id
             WHERE t.organisation_id = ? AND t.kind = ? AND t.active = 1 ORDER BY t.id, i.sort_order, i.id",
            [$orgId, $kind]
        );
        foreach ($items as $i) {
            DB::insert('tasks', [
                'uuid' => Uuid::v4(), 'organisation_id' => $orgId, 'person_id' => $personId, 'employment_id' => $employmentId,
                'kind' => $kind, 'title' => $i['title'], 'description' => $i['description'], 'owner_role' => $i['owner_role'],
                'due_date' => date('Y-m-d', strtotime($baseDate . ' ' . ((int) $i['due_offset_days'] >= 0 ? '+' : '') . (int) $i['due_offset_days'] . ' days')),
            ]);
        }
        return count($items);
    }

    /** A one-off task, for example "find a new key worker". */
    public static function createTask(int $orgId, string $kind, int $personId, ?int $employmentId, string $title, ?string $description, ?string $due, string $ownerRole = 'manager'): void
    {
        DB::insert('tasks', [
            'uuid' => Uuid::v4(), 'organisation_id' => $orgId, 'person_id' => $personId, 'employment_id' => $employmentId,
            'kind' => $kind, 'title' => mb_substr($title, 0, 200), 'description' => $description ? mb_substr($description, 0, 500) : null,
            'due_date' => $due, 'owner_role' => $ownerRole,
        ]);
    }

    public static function setStatus(int $orgId, string $taskUuid, string $status, int $userId, Actor $actor): void
    {
        if (!in_array($status, ['done', 'skipped', 'open'], true)) {
            throw new DomainException('Choose a valid status.');
        }
        $t = DB::one('SELECT t.*, p.uuid AS person_uuid FROM tasks t JOIN persons p ON p.id = t.person_id WHERE t.uuid = ? AND t.organisation_id = ?', [$taskUuid, $orgId]) ?? throw new DomainException('Task not found.');
        DB::transaction(function () use ($orgId, $t, $status, $userId, $actor) {
            DB::update('tasks', [
                'status' => $status,
                'completed_by_user_id' => $status === 'open' ? null : $userId,
                'completed_at' => $status === 'open' ? null : gmdate('Y-m-d H:i:s'),
            ], ['id' => $t['id']]);
            Audit::record($orgId, $actor, 'task.' . $status, 'person', $t['person_uuid'], 'Task "' . $t['title'] . '" marked ' . $status);
            if ($status === 'done') {
                Events::emit($orgId, 'task.completed', $t['person_uuid'], ['task' => $t['uuid'], 'kind' => $t['kind']]);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public static function open(int $orgId, ?string $role = null, int $limit = 200): array
    {
        $sql = "SELECT t.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM tasks t JOIN persons p ON p.id = t.person_id
                WHERE t.organisation_id = ? AND t.status = 'open'";
        $params = [$orgId];
        if ($role !== null) {
            $sql .= ' AND t.owner_role = ?';
            $params[] = $role;
        }
        return DB::all($sql . ' ORDER BY (t.due_date IS NULL), t.due_date, t.id LIMIT ' . (int) $limit, $params);
    }

    /** @return list<array<string,mixed>> */
    public static function forPerson(int $personId): array
    {
        return DB::all('SELECT * FROM tasks WHERE person_id = ? ORDER BY (status = \'open\') DESC, due_date, id', [$personId]);
    }

    /** The default onboarding, move and offboarding lists a care provider would start from. */
    public static function seedDefaults(int $orgId): void
    {
        if (DB::value('SELECT 1 FROM checklist_templates WHERE organisation_id = ? LIMIT 1', [$orgId])) {
            return;
        }
        $sets = [
            ['joiner', 'New starter', [
                ['Check right to work and identity documents', 'admin', -3], ['Complete PVG or disclosure check', 'admin', -3],
                ['Confirm professional registration (for example SSSC)', 'manager', 0], ['Issue a login and equipment', 'admin', 0],
                ['Induction and shadow shifts planned', 'manager', 0], ['Mandatory training booked', 'manager', 7], ['First supervision booked', 'manager', 28],
            ]],
            ['mover', 'Change of role or team', [
                ['Update rota and permissions', 'manager', 0], ['Confirm training for the new role', 'manager', 14],
            ]],
            ['leaver', 'Leaver', [
                ['Exit conversation', 'manager', -7], ['Return keys, ID badge and equipment', 'manager', 0], ['Remove system access', 'admin', 0],
                ['Final pay and leave balance sent to payroll', 'admin', 0], ['Handover of people they support', 'manager', -3],
            ]],
        ];
        foreach ($sets as [$kind, $name, $items]) {
            $tid = DB::insert('checklist_templates', ['organisation_id' => $orgId, 'kind' => $kind, 'name' => $name]);
            foreach ($items as $n => [$title, $role, $offset]) {
                DB::insert('checklist_template_items', ['template_id' => $tid, 'title' => $title, 'owner_role' => $role, 'due_offset_days' => $offset, 'sort_order' => $n]);
            }
        }
    }
}
