<?php
declare(strict_types=1);

namespace App\Modules\Recruitment;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;
use App\Modules\Staff\Lifecycle;

/**
 * Applications: a candidate for a vacancy, from applied through interview and offer to hired (or not).
 *
 * A candidate is not a person until they are hired, so unsuccessful applicants never appear in the people register and
 * their details can be removed when they are no longer needed. Hiring does not reimplement starting someone: it calls the
 * ordinary joiner process, so the person, the employment, the onboarding checklist and the employment.started event all
 * come from there, exactly as if someone had been added by hand.
 */
final class ApplicationRepo
{
    public const STAGES = ['applied', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'];
    public const OPEN_STAGES = ['applied', 'shortlisted', 'interview', 'offer'];

    /** Where each stage may go next. Hired is only ever reached by hiring. */
    private const NEXT = [
        'applied'     => ['shortlisted', 'rejected', 'withdrawn'],
        'shortlisted' => ['applied', 'interview', 'offer', 'rejected', 'withdrawn'],
        'interview'   => ['shortlisted', 'offer', 'rejected', 'withdrawn'],
        'offer'       => ['interview', 'rejected', 'withdrawn'],
        'rejected'    => ['applied'],
        'withdrawn'   => ['applied'],
        'hired'       => [],
    ];

    /** @param array<string,mixed> $d @return list<string> */
    public static function problems(array $d): array
    {
        return Check::all([
            Check::required($d['first_name'] ?? null, 'First name'),
            Check::required($d['last_name'] ?? null, 'Last name'),
            Check::maxLen($d['first_name'] ?? null, 100, 'First name'),
            Check::maxLen($d['last_name'] ?? null, 100, 'Last name'),
            Check::email($d['email'] ?? null),
            Check::maxLen($d['phone'] ?? null, 40, 'Phone'),
            Check::date($d['date_of_birth'] ?? null, 'Date of birth'),
            (($d['date_of_birth'] ?? '') !== '' && Clock::valid($d['date_of_birth']) && $d['date_of_birth'] > Clock::today()) ? 'Date of birth cannot be in the future.' : null,
            Check::maxLen($d['source'] ?? null, 100, 'Where they heard about it'),
            Check::maxLen($d['notes'] ?? null, 5000, 'Notes'),
        ]);
    }

    /**
     * A candidate applies (or is added) to an open vacancy. The role's checks are copied to the application.
     *
     * @param array<string,mixed> $d first_name, last_name, email, phone, date_of_birth, source, notes
     * @return array{id:int,uuid:string}
     */
    public static function create(int $orgId, int $vacancyId, array $d, Actor $actor): array
    {
        $v = VacancyRepo::byId($orgId, $vacancyId) ?? throw new DomainException('Vacancy not found.');
        if ($v['status'] !== 'open') {
            throw new DomainException('Candidates can only be added to an open vacancy.');
        }
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $email = trim((string) ($d['email'] ?? '')) !== '' ? strtolower(trim((string) $d['email'])) : null;
        if ($email !== null && DB::value("SELECT 1 FROM applications WHERE vacancy_id = ? AND email = ? AND stage NOT IN ('rejected','withdrawn')", [$vacancyId, $email])) {
            throw new DomainException('Someone with that email address has already applied for this vacancy.');
        }
        return DB::transaction(function () use ($orgId, $v, $d, $email, $actor) {
            $uuid = Uuid::v4();
            $id = DB::insert('applications', [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'vacancy_id' => $v['id'],
                'first_name' => trim($d['first_name']), 'last_name' => trim($d['last_name']), 'email' => $email,
                'phone' => self::nullable($d['phone'] ?? null), 'date_of_birth' => self::nullable($d['date_of_birth'] ?? null),
                'source' => self::nullable($d['source'] ?? null), 'notes' => self::nullable($d['notes'] ?? null),
                'stage' => 'applied', 'stage_changed_on' => Clock::today(),
            ]);
            foreach (RoleRepo::requirements((int) $v['role_id']) as $r) {
                DB::insert('application_checks', ['uuid' => Uuid::v4(), 'application_id' => $id, 'title' => $r['title'], 'description' => $r['description'], 'is_required' => $r['is_required'], 'sort_order' => $r['sort_order']]);
            }
            Audit::record($orgId, $actor, 'application.received', 'application', $uuid, 'Application from ' . trim($d['first_name']) . ' ' . trim($d['last_name']) . ' for ' . $v['reference'], ['vacancy' => $v['uuid']]);
            Events::emit($orgId, 'application.received', $uuid, ['vacancy' => $v['uuid'], 'reference' => $v['reference']]);
            return ['id' => $id, 'uuid' => $uuid];
        });
    }

    /** Edit what is known about the candidate. Not once they have been hired (they are a person then). @param array<string,mixed> $d */
    public static function update(int $orgId, int $applicationId, array $d, Actor $actor): void
    {
        $a = self::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        if ($a['stage'] === 'hired') {
            throw new DomainException('They have been hired, so their details are in People now.');
        }
        $keys = ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'source', 'notes', 'interview_notes'];
        $merged = array_intersect_key($d, array_flip($keys)) + array_intersect_key($a, array_flip($keys));
        if ($p = self::problems($merged + ['notes' => $merged['notes'] ?? null]) ?: Check::all([Check::maxLen($merged['interview_notes'] ?? null, 10000, 'Interview notes')])) {
            throw DomainException::of($p);
        }
        $set = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $d)) {
                $set[$k] = $k === 'email' ? (self::nullable($d[$k]) !== null ? strtolower((string) self::nullable($d[$k])) : null) : (in_array($k, ['first_name', 'last_name'], true) ? trim((string) $d[$k]) : self::nullable($d[$k]));
            }
        }
        if ($set) {
            DB::update('applications', $set, ['id' => $applicationId]);
            Audit::record($orgId, $actor, 'application.updated', 'application', $a['uuid'], 'Updated the application from ' . $a['first_name'] . ' ' . $a['last_name']);
        }
    }

    /**
     * Move an application on (or back, or out). Hired is reached only through hire().
     *
     * @param array<string,mixed> $d interview_date (interview), offer_date and proposed_start_date (offer), reason (rejected, withdrawn)
     */
    public static function setStage(int $orgId, int $applicationId, string $stage, array $d, Actor $actor): void
    {
        $a = self::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        if (!in_array($stage, self::STAGES, true)) {
            throw new DomainException('That is not a stage an application can be in.');
        }
        if ($stage === 'hired') {
            throw new DomainException('Use Hire to start someone: it creates their record and onboarding.');
        }
        if ($stage === $a['stage']) {
            throw new DomainException('It is already at that stage.');
        }
        if (!in_array($stage, self::NEXT[$a['stage']] ?? [], true)) {
            throw new DomainException('An application at "' . $a['stage'] . '" cannot go straight to "' . $stage . '".');
        }
        $v = VacancyRepo::byId($orgId, (int) $a['vacancy_id']);
        if ($stage === 'applied' && $v && $v['status'] !== 'open') {
            throw new DomainException('The vacancy is not open, so the application cannot be reopened.');
        }
        $set = ['stage' => $stage, 'stage_changed_on' => Clock::today()];
        $msgs = [];
        if ($stage === 'interview') {
            $msgs[] = Check::date($d['interview_date'] ?? null, 'Interview date');
            $set['interview_date'] = ($d['interview_date'] ?? '') ?: ($a['interview_date'] ?: null);
        }
        if ($stage === 'offer') {
            $start = $d['proposed_start_date'] ?? $a['proposed_start_date'] ?? '';
            $msgs[] = Check::date($start, 'Proposed start date', true);
            $msgs[] = Check::date($d['offer_date'] ?? null, 'Offer date');
            $set['proposed_start_date'] = $start;
            $set['offer_date'] = ($d['offer_date'] ?? '') ?: Clock::today();
            $set['offer_accepted_on'] = null;
        }
        if (in_array($stage, ['rejected', 'withdrawn'], true)) {
            $msgs[] = Check::maxLen($d['reason'] ?? null, 500, 'Reason');
            $set['reason'] = self::nullable($d['reason'] ?? null);
        } elseif (in_array($a['stage'], ['rejected', 'withdrawn'], true)) {
            $set['reason'] = null;
        }
        if ($m = Check::all($msgs)) {
            throw DomainException::of($m);
        }
        DB::transaction(function () use ($orgId, $a, $set, $stage, $actor, $v) {
            DB::update('applications', $set, ['id' => $a['id']]);
            Audit::record($orgId, $actor, 'application.stage', 'application', $a['uuid'], $a['first_name'] . ' ' . $a['last_name'] . ': ' . $a['stage'] . ' → ' . $stage, ['from' => $a['stage'], 'to' => $stage]);
            Events::emit($orgId, 'application.stage_changed', $a['uuid'], ['from' => $a['stage'], 'to' => $stage, 'vacancy' => $v['uuid'] ?? null]);
        });
    }

    /** The candidate accepts the offer. Hiring needs this. */
    public static function acceptOffer(int $orgId, int $applicationId, ?string $on, Actor $actor): void
    {
        $a = self::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        if ($a['stage'] !== 'offer') {
            throw new DomainException('There is no offer to accept: move them to the Offer stage first.');
        }
        $on = ($on ?? '') ?: Clock::today();
        if ($m = Check::date($on, 'Date accepted', true)) {
            throw new DomainException($m);
        }
        if ($on > Clock::today()) {
            throw new DomainException('The date the offer was accepted cannot be in the future.');
        }
        DB::update('applications', ['offer_accepted_on' => $on], ['id' => $applicationId]);
        Audit::record($orgId, $actor, 'application.offer_accepted', 'application', $a['uuid'], $a['first_name'] . ' ' . $a['last_name'] . ' accepted the offer');
    }

    /** Tick a check off for this candidate, or clear it. */
    public static function setCheck(int $orgId, int $applicationId, int $checkId, ?string $doneOn, ?string $notes, Actor $actor): void
    {
        $a = self::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        $c = DB::one('SELECT * FROM application_checks WHERE id = ? AND application_id = ?', [$checkId, $applicationId]) ?? throw new DomainException('Check not found.');
        if ($doneOn !== null && $doneOn !== '') {
            if ($m = Check::date($doneOn, 'Date done', true)) {
                throw new DomainException($m);
            }
            if ($doneOn > Clock::today()) {
                throw new DomainException('The date a check was done cannot be in the future.');
            }
        }
        if ($m = Check::maxLen($notes, 500, 'Notes')) {
            throw new DomainException($m);
        }
        DB::update('application_checks', ['done_on' => ($doneOn ?? '') !== '' ? $doneOn : null, 'notes' => self::nullable($notes)], ['id' => $checkId]);
        Audit::record($orgId, $actor, 'application.check', 'application', $a['uuid'], $a['first_name'] . ' ' . $a['last_name'] . ': ' . $c['title'] . (($doneOn ?? '') !== '' ? ' done' : ' cleared'));
    }

    /** What still stands between this candidate and a start: the required checks not yet done. @return list<string> */
    public static function outstanding(int $applicationId): array
    {
        return array_column(DB::all('SELECT title FROM application_checks WHERE application_id = ? AND is_required = 1 AND done_on IS NULL ORDER BY sort_order, id', [$applicationId]), 'title');
    }

    /**
     * Hire: the candidate becomes a person with an employment, through the ordinary joiner process.
     *
     * @param array<string,mixed> $d start_date, job_title, employee_ref, line_manager_person_id, team_id, create_login, login_role (all default sensibly)
     * @return array{person_uuid:string,employment_uuid:string,invite_token:?string,tasks:int,rehire:bool,filled:bool}
     */
    public static function hire(int $orgId, int $applicationId, array $d, Actor $actor): array
    {
        $a = self::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        $v = VacancyRepo::byId($orgId, (int) $a['vacancy_id']) ?? throw new DomainException('Vacancy not found.');
        if ($a['stage'] === 'hired') {
            throw new DomainException('They have already been hired.');
        }
        if ($a['stage'] !== 'offer') {
            throw new DomainException('They can only be hired once they have an offer. Move them to the Offer stage first.');
        }
        if (empty($a['offer_accepted_on'])) {
            throw new DomainException('Record that they accepted the offer first.');
        }
        if (in_array($v['status'], ['closed', 'filled'], true)) {
            throw new DomainException('This vacancy is ' . $v['status'] . '. Reopen it to hire someone.');
        }
        if (VacancyRepo::hired((int) $v['id']) >= (int) $v['posts']) {
            throw new DomainException('Every post on this vacancy has been filled.');
        }
        if ($open = self::outstanding($applicationId)) {
            throw new DomainException('These checks must be done before they can start: ' . implode('; ', $open) . '.');
        }

        $start = ($d['start_date'] ?? '') ?: ($a['proposed_start_date'] ?: Clock::today());
        $join = [
            'job_title' => trim((string) ($d['job_title'] ?? '')) !== '' ? trim((string) $d['job_title']) : $v['title'],
            'contract_type' => $v['contract_type'], 'hours_per_week' => $v['hours_per_week'], 'start_date' => $start,
            'employee_ref' => $d['employee_ref'] ?? '', 'line_manager_person_id' => array_key_exists('line_manager_person_id', $d) ? $d['line_manager_person_id'] : $v['line_manager_person_id'],
            'team_id' => array_key_exists('team_id', $d) && $d['team_id'] !== '' ? $d['team_id'] : $v['team_id'],
            'create_login' => !empty($d['create_login']), 'login_role' => ($d['login_role'] ?? '') ?: 'staff',
        ];
        if (($d['contract_type'] ?? '') !== '') {
            $join['contract_type'] = $d['contract_type'];
        }
        if (array_key_exists('hours_per_week', $d) && $d['hours_per_week'] !== '') {
            $join['hours_per_week'] = $d['hours_per_week'];
        }

        return DB::transaction(function () use ($orgId, $a, $v, $join, $actor) {
            $person = self::matchingPerson($orgId, $a);
            if ($person) {
                $join['person_uuid'] = $person['uuid'];
            } else {
                $join += ['first_name' => $a['first_name'], 'last_name' => $a['last_name'], 'email' => $a['email'], 'phone' => $a['phone'], 'date_of_birth' => $a['date_of_birth']];
            }
            if (!empty($join['create_login']) && !$person && empty($a['email'])) {
                throw new DomainException('A login needs an email address, and this candidate has none.');
            }
            $res = Lifecycle::join($orgId, $join, $actor);
            DB::update('applications', [
                'stage' => 'hired', 'stage_changed_on' => Clock::today(), 'person_id' => $res['person_id'], 'employment_id' => $res['employment_id'],
            ], ['id' => $a['id']]);
            Audit::record($orgId, $actor, 'application.hired', 'application', $a['uuid'], $a['first_name'] . ' ' . $a['last_name'] . ' hired as ' . $join['job_title'] . ' (' . $v['reference'] . ')', ['person' => $res['person_uuid'], 'employment' => $res['employment_uuid']]);
            Events::emit($orgId, 'application.hired', $a['uuid'], ['vacancy' => $v['uuid'], 'role' => DB::value('SELECT uuid FROM job_roles WHERE id = ?', [$v['role_id']]), 'person' => $res['person_uuid'], 'employment' => $res['employment_uuid'], 'start_date' => $join['start_date']]);
            $filled = VacancyRepo::checkFilled($orgId, (int) $v['id'], $actor);
            return ['person_uuid' => $res['person_uuid'], 'employment_uuid' => $res['employment_uuid'], 'invite_token' => $res['invite_token'], 'tasks' => $res['tasks'], 'rehire' => $res['rehire'], 'filled' => $filled];
        });
    }

    /** The person this candidate already is, if exactly one: by email, else by name and date of birth. @param array<string,mixed> $a @return array<string,mixed>|null */
    private static function matchingPerson(int $orgId, array $a): ?array
    {
        if (!empty($a['email'])) {
            $p = DB::one('SELECT * FROM persons WHERE organisation_id = ? AND email = ?', [$orgId, strtolower((string) $a['email'])]);
            if ($p) {
                return $p;
            }
        }
        if (!empty($a['date_of_birth'])) {
            $m = DB::all('SELECT * FROM persons WHERE organisation_id = ? AND first_name = ? AND last_name = ? AND date_of_birth = ?', [$orgId, $a['first_name'], $a['last_name'], $a['date_of_birth']]);
            if (count($m) === 1) {
                return $m[0];
            }
        }
        return null;
    }

    /** Let everyone still in progress know the vacancy is over. @return int how many */
    public static function closeRemaining(int $orgId, int $vacancyId, string $reason, Actor $actor): int
    {
        $rows = DB::all("SELECT * FROM applications WHERE vacancy_id = ? AND stage IN ('applied','shortlisted','interview','offer')", [$vacancyId]);
        foreach ($rows as $a) {
            DB::update('applications', ['stage' => 'rejected', 'stage_changed_on' => Clock::today(), 'reason' => $reason], ['id' => $a['id']]);
            Audit::record($orgId, $actor, 'application.stage', 'application', $a['uuid'], $a['first_name'] . ' ' . $a['last_name'] . ': ' . $a['stage'] . ' → rejected', ['from' => $a['stage'], 'to' => 'rejected']);
        }
        return count($rows);
    }

    /** Remove a finished candidate's personal details (they are not a person here). Not for someone who was hired. */
    public static function removeDetails(int $orgId, int $applicationId, Actor $actor): void
    {
        $a = self::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        if (!in_array($a['stage'], ['rejected', 'withdrawn'], true)) {
            throw new DomainException('Only the details of someone who was not hired can be removed.');
        }
        DB::update('applications', ['first_name' => 'Removed', 'last_name' => 'applicant', 'email' => null, 'phone' => null, 'date_of_birth' => null, 'source' => null, 'notes' => null, 'interview_notes' => null], ['id' => $applicationId]);
        Audit::record($orgId, $actor, 'application.erased', 'application', $a['uuid'], 'Removed the personal details of an unsuccessful applicant');
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byId(int $orgId, int $id): ?array
    {
        return DB::one('SELECT * FROM applications WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
    }

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM applications WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function forVacancy(int $vacancyId, ?string $stage = null): array
    {
        return DB::all(
            'SELECT a.*, (SELECT COUNT(*) FROM application_checks c WHERE c.application_id = a.id AND c.is_required = 1) AS checks_required,
                    (SELECT COUNT(*) FROM application_checks c WHERE c.application_id = a.id AND c.is_required = 1 AND c.done_on IS NOT NULL) AS checks_done
             FROM applications a WHERE a.vacancy_id = ?' . ($stage ? ' AND a.stage = ?' : '') . ' ORDER BY a.id',
            $stage ? [$vacancyId, $stage] : [$vacancyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function checks(int $applicationId): array
    {
        return DB::all('SELECT * FROM application_checks WHERE application_id = ? ORDER BY sort_order, id', [$applicationId]);
    }

    private static function nullable(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
