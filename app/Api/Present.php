<?php
declare(strict_types=1);

namespace App\Api;

/** How each thing looks in the API. Public ids are always UUIDs; internal numeric ids never appear. Dates are YYYY-MM-DD, times are UTC ISO 8601. */
final class Present
{
    public static function time(?string $dt): ?string
    {
        return $dt ? str_replace(' ', 'T', substr($dt, 0, 19)) . 'Z' : null;
    }

    public static function bool(mixed $v): bool
    {
        return (bool) (int) $v;
    }

    public static function num(mixed $v): int|float|null
    {
        return $v === null || $v === '' ? null : (str_contains((string) $v, '.') ? (float) $v : (int) $v);
    }

    /** Expects persons.* plus is_staff, is_supported and cur_* (the current employment), as the people queries return. @param array<string,mixed> $r @return array<string,mixed> */
    public static function person(array $r): array
    {
        return [
            'id' => $r['uuid'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'preferred_name' => $r['preferred_name'],
            'date_of_birth' => $r['date_of_birth'], 'email' => $r['email'], 'phone' => $r['phone'], 'notes' => $r['notes'],
            'sex' => $r['sex'] ?? null, 'nhs_number' => $r['nhs_number'] ?? null, 'employment_status' => $r['employment_status'] ?? null, 'gp_practice' => $r['gp_practice'] ?? null,
            'address_line1' => $r['address_line1'] ?? null, 'address_line2' => $r['address_line2'] ?? null, 'town' => $r['town'] ?? null, 'area' => $r['area'] ?? null, 'postcode' => $r['postcode'] ?? null,
            'is_staff' => self::bool($r['is_staff'] ?? 0), 'is_supported' => self::bool($r['is_supported'] ?? 0),
            'current_employment' => !empty($r['cur_emp_uuid']) ? ['id' => $r['cur_emp_uuid'], 'job_title' => $r['cur_job'], 'start_date' => $r['cur_start'], 'end_date' => $r['cur_end']] : null,
            'created_at' => self::time($r['created_at']), 'updated_at' => self::time($r['updated_at']),
        ];
    }

    /** Expects employments.* plus person_uuid and manager_uuid. @param array<string,mixed> $r @return array<string,mixed> */
    public static function employment(array $r, ?string $today = null): array
    {
        $today ??= \App\Core\Clock::today();
        $status = $r['start_date'] > $today ? 'upcoming' : (($r['end_date'] !== null && $r['end_date'] < $today) ? 'ended' : 'current');
        return [
            'id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'employee_ref' => $r['employee_ref'], 'job_title' => $r['job_title'],
            'contract_type' => $r['contract_type'], 'hours_per_week' => self::num($r['hours_per_week']), 'start_date' => $r['start_date'],
            'end_date' => $r['end_date'], 'leaving_reason' => $r['leaving_reason'], 'line_manager_id' => $r['manager_uuid'] ?? null,
            'status' => $status, 'created_at' => self::time($r['created_at']),
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function change(array $r): array
    {
        return ['effective_date' => $r['effective_date'], 'field' => $r['field'], 'from' => $r['from_value'], 'to' => $r['to_value']];
    }

    /** Expects teams.* plus parent_uuid. @param array<string,mixed> $r @return array<string,mixed> */
    public static function team(array $r): array
    {
        return ['id' => $r['uuid'], 'name' => $r['name'], 'parent_id' => $r['parent_uuid'] ?? null, 'kind' => $r['kind'], 'staff_only' => self::bool($r['staff_only']), 'active' => self::bool($r['active']), 'created_at' => self::time($r['created_at'])];
    }

    /** Expects team_assignments.* plus team_uuid and person_uuid. @param array<string,mixed> $r @return array<string,mixed> */
    public static function place(array $r): array
    {
        return ['id' => $r['uuid'], 'team_id' => $r['team_uuid'], 'person_id' => $r['person_uuid'], 'role' => $r['role'], 'primary' => self::bool($r['is_primary']), 'start_date' => $r['valid_from'], 'end_date' => $r['valid_to']];
    }

    /** Expects support_episodes.* plus person_uuid. @param array<string,mixed> $r @return array<string,mixed> */
    public static function episode(array $r): array
    {
        return ['id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'reference' => $r['reference'], 'nhs_number' => $r['nhs_number'], 'local_authority_ref' => $r['local_authority_ref']];
    }

    /** Expects key_worker_assignments.* plus supported_uuid and staff_uuid. @param array<string,mixed> $r @return array<string,mixed> */
    public static function keyWorker(array $r): array
    {
        return ['id' => $r['uuid'], 'supported_person_id' => $r['supported_uuid'], 'staff_person_id' => $r['staff_uuid'], 'role_label' => $r['role_label'], 'start_date' => $r['valid_from'], 'end_date' => $r['valid_to']];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function need(array $r): array
    {
        return ['id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'category' => $r['category'], 'description' => $r['description'], 'severity' => $r['severity'], 'review_date' => $r['review_date'], 'ended_on' => $r['ended_on']];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function contact(array $r): array
    {
        return ['id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'type' => $r['contact_type'], 'label' => $r['label'], 'value' => $r['value'], 'primary' => self::bool($r['is_primary'])];
    }

    /** Expects tasks.* plus person_uuid. @param array<string,mixed> $r @return array<string,mixed> */
    public static function task(array $r): array
    {
        return ['id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'kind' => $r['kind'], 'title' => $r['title'], 'description' => $r['description'], 'status' => $r['status'], 'due_date' => $r['due_date'], 'owner_role' => $r['owner_role'], 'completed_at' => self::time($r['completed_at'])];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function event(array $r): array
    {
        $env = json_decode((string) $r['payload'], true) ?: [];
        return ['cursor' => self::cursor((int) $r['id']), 'id' => $r['uuid'], 'type' => $r['type'], 'occurred_at' => $env['occurred_at'] ?? self::time($r['occurred_at']), 'subject' => $r['subject_uuid'], 'data' => $env['data'] ?? (object) []];
    }

    public static function cursor(int $id): string
    {
        return rtrim(strtr(base64_encode('v1:' . $id), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function webhook(array $r): array
    {
        return ['id' => $r['uuid'], 'url' => $r['url'], 'event_types' => array_values(array_filter(array_map('trim', explode(',', (string) $r['event_types'])))), 'active' => self::bool($r['active']), 'created_at' => self::time($r['created_at'])];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function audit(array $r): array
    {
        return ['cursor' => self::cursor((int) $r['id']), 'when' => self::time($r['created_at']), 'action' => $r['action'], 'actor' => $r['actor_label'], 'subject_type' => $r['entity_type'], 'subject_id' => $r['entity_uuid'], 'summary' => $r['summary'], 'detail' => $r['detail'] ? json_decode((string) $r['detail'], true) : null];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function user(array $r): array
    {
        return ['id' => $r['uuid'], 'email' => $r['email'], 'role' => $r['role'], 'status' => $r['status'], 'access_ends_on' => $r['access_ends_on'], 'person_id' => $r['person_uuid'] ?? null, 'last_login_at' => self::time($r['last_login_at'])];
    }
}
