<?php
declare(strict_types=1);

namespace App\Api;

/** The response schemas, for the OpenAPI document. They mirror App\Api\Present. */
final class Models
{
    /** @return array<string,mixed> */
    public static function schemas(): array
    {
        $str = ['type' => 'string'];
        $ns = ['type' => ['string', 'null']];
        $date = ['type' => ['string', 'null'], 'format' => 'date'];
        $dt = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $id = ['type' => 'string', 'format' => 'uuid'];
        $nid = ['type' => ['string', 'null'], 'format' => 'uuid'];
        $bool = ['type' => 'boolean'];
        $int = ['type' => 'integer'];
        $num = ['type' => ['number', 'null']];
        $obj = static fn(array $p) => ['type' => 'object', 'properties' => $p];
        $arr = static fn(array $items) => ['type' => 'array', 'items' => $items];

        $employment = $obj(['id' => $id, 'person_id' => $id, 'employee_ref' => $ns, 'job_title' => $str, 'contract_type' => $str, 'hours_per_week' => $num, 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => $date, 'leaving_reason' => $ns, 'line_manager_id' => $nid, 'status' => ['type' => 'string', 'enum' => ['upcoming', 'current', 'ended']], 'created_at' => $dt]);
        $place = $obj(['id' => $id, 'team_id' => $id, 'person_id' => $id, 'role' => $ns, 'primary' => $bool, 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => $date]);
        $registration = ['id' => $id, 'person_id' => $id, 'registration_type' => $str, 'registration_body' => $ns, 'registration_number' => $ns, 'issue_date' => $date, 'expiry_date' => ['type' => 'string', 'format' => 'date'], 'is_required_for_role' => $bool, 'notes' => $ns, 'created_at' => $dt];

        return [
            'Page' => $obj(['limit' => $int, 'next_cursor' => $ns, 'has_more' => $bool]),
            'Person' => $obj(['id' => $id, 'first_name' => $str, 'last_name' => $str, 'preferred_name' => $ns, 'date_of_birth' => $date, 'email' => $ns, 'phone' => $ns, 'notes' => $ns, 'is_staff' => $bool, 'is_supported' => ['type' => ['boolean', 'null']],
                'current_employment' => ['type' => ['object', 'null'], 'properties' => ['id' => $id, 'job_title' => $str, 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => $date]], 'created_at' => $dt, 'updated_at' => $dt]),
            'Employment' => $employment,
            'EmploymentChange' => $obj(['effective_date' => ['type' => 'string', 'format' => 'date'], 'field' => $str, 'from' => $ns, 'to' => $ns]),
            'LeavePreview' => $obj(['last_day' => ['type' => 'string', 'format' => 'date'], 'team_places_that_will_end' => $arr($str), 'people_who_lose_this_key_worker' => $int, 'people_who_lose_this_line_manager' => $int, 'logins_that_will_stop' => $int, 'offboarding_tasks_that_will_be_created' => $int]),
            'LeaveResult' => $obj(['employment' => $employment, 'team_places_ended' => $int, 'key_worker_links_ended' => $int, 'reassign_tasks_for_reports' => $int, 'logins_set_to_end' => $int, 'offboarding_tasks_created' => $int]),
            'EmploymentDetails' => ['type' => 'object', 'description' => 'One object per section (terms, tupe, wtd, apprentice, visa, leave), each with its fields.', 'additionalProperties' => ['type' => 'object']],
            'Profile' => ['type' => 'object', 'description' => 'Address, emergency contact, has_photo, has_signature and (with staff.sensitive) National Insurance and bank fields.', 'additionalProperties' => true],
            'ProfileChange' => $obj(['id' => $id, 'person_id' => $id, 'field' => $str, 'label' => $str, 'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'withdrawn']], 'current_value' => $ns, 'proposed_value' => $ns, 'rejection_reason' => $ns, 'submitted_at' => $dt, 'reviewed_at' => $dt]),
            'Registration' => $obj($registration),
            'Supervision' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'id, person_id, supervision_date, supervisor_person_id, supervision_type, duration_minutes, outcome, next_due_date, notes, created_at.'],
            'Appraisal' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'id, person_id, appraisal_date, appraiser_person_id, appraisal_type, outcome, next_due_date, notes, created_at.'],
            'Learning' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'id, person_id, title, record_type, provider, qualification_level, completion_date, expiry_date, certificate_number, status, is_mandatory, notes, created_at.'],
            'DueItem' => $obj(['person_id' => $id, 'kind' => ['type' => 'string', 'enum' => ['registration', 'learning', 'supervision', 'appraisal']], 'what' => $str, 'label' => $ns, 'due' => ['type' => 'string', 'format' => 'date'], 'overdue' => $bool]),
            'Team' => $obj(['id' => $id, 'name' => $str, 'parent_id' => $nid, 'kind' => $str, 'staff_only' => $bool, 'active' => $bool, 'created_at' => $dt]),
            'TeamPlace' => $place,
            'SupportEpisode' => $obj(['id' => $id, 'person_id' => $id, 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => $date, 'reference' => $ns, 'nhs_number' => $ns, 'local_authority_ref' => $ns]),
            'SupportEnded' => $obj(['key_worker_links_ended' => $int, 'team_places_ended' => $int]),
            'CareNeed' => $obj(['id' => $id, 'person_id' => $id, 'category' => $str, 'description' => $str, 'severity' => $ns, 'review_date' => $date, 'ended_on' => $date]),
            'Contact' => $obj(['id' => $id, 'person_id' => $id, 'type' => $str, 'label' => $ns, 'value' => $str, 'primary' => $bool]),
            'KeyWorker' => $obj(['id' => $id, 'supported_person_id' => $id, 'staff_person_id' => $id, 'role_label' => $ns, 'start_date' => ['type' => 'string', 'format' => 'date'], 'end_date' => $date]),
            'Task' => $obj(['id' => $id, 'person_id' => $id, 'kind' => ['type' => 'string', 'enum' => ['joiner', 'mover', 'leaver', 'reassign', 'manual']], 'title' => $str, 'description' => $ns, 'status' => ['type' => 'string', 'enum' => ['open', 'done', 'skipped']], 'due_date' => $date, 'owner_role' => $str, 'completed_at' => $dt]),
            'Event' => $obj(['cursor' => $str, 'id' => $id, 'type' => $str, 'occurred_at' => ['type' => 'string', 'format' => 'date-time'], 'subject' => $nid, 'data' => ['type' => 'object', 'additionalProperties' => true]]),
            'Webhook' => $obj(['id' => $id, 'url' => $str, 'event_types' => $arr($str), 'active' => $bool, 'created_at' => $dt, 'secret' => ['type' => 'string', 'description' => 'Only in the response that creates the endpoint.']]),
            'WebhookDelivery' => $obj(['event_id' => $id, 'event_type' => $str, 'status' => ['type' => 'string', 'enum' => ['pending', 'delivered', 'failed']], 'attempts' => $int, 'http_status' => ['type' => ['integer', 'null']], 'error' => $ns, 'next_attempt_at' => $dt, 'delivered_at' => $dt, 'created_at' => $dt]),
            'AuditEntry' => $obj(['cursor' => $str, 'when' => $dt, 'action' => $str, 'actor' => $ns, 'subject_type' => $str, 'subject_id' => $nid, 'summary' => $str, 'detail' => ['type' => ['object', 'null'], 'additionalProperties' => true]]),
            'User' => $obj(['id' => $id, 'email' => $str, 'role' => ['type' => 'string', 'enum' => ['admin', 'manager', 'staff']], 'status' => ['type' => 'string', 'enum' => ['active', 'invited', 'disabled']], 'access_ends_on' => $date, 'person_id' => $nid, 'last_login_at' => $dt]),
            'ConsistencyFinding' => $obj(['code' => $str, 'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']], 'person_id' => $id, 'detail' => $str, 'fixable' => $bool]),
            'Ping' => $obj(['organisation' => $obj(['id' => $id, 'name' => $str]), 'key' => $obj(['id' => $id, 'name' => $str, 'scopes' => $arr($str), 'rate_limit_per_minute' => $int, 'expires_at' => $dt]), 'server_time' => ['type' => 'string', 'format' => 'date-time'], 'api_version' => $str]),
            'Scope' => $obj(['scope' => $str, 'description' => $str, 'granted' => $bool]),
        ];
    }
}
