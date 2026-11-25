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

/**
 * Interviews: a booked time for a candidate. Booking one from the shortlist moves the application to "interview" and
 * records the date on it, so the existing pipeline stays the single source of truth. The candidate gets a private link
 * (no account) where they can see the details and confirm, or say they cannot come. Care Core sends no email: staff copy
 * the invitation text (see invitation()) and send it from their own mailbox.
 */
final class InterviewRepo
{
    /** @param array<string,mixed> $d @return list<string> */
    public static function problems(array $d): array
    {
        $when = self::parse($d['starts_at'] ?? null);
        $mins = $d['duration_minutes'] ?? 45;
        return Check::all([
            $when === null ? 'Choose a date and time for the interview.' : null,
            Check::required($d['location'] ?? null, 'Where it is'),
            Check::maxLen($d['location'] ?? null, 200, 'Where it is'),
            Check::maxLen($d['panel'] ?? null, 300, 'Who will interview'),
            Check::maxLen($d['instructions'] ?? null, 1000, 'What to bring'),
            (is_numeric($mins) && (int) $mins >= 10 && (int) $mins <= 480) ? null : 'How long must be between 10 and 480 minutes.',
        ]);
    }

    /** "2026-12-01T10:30" or "2026-12-01 10:30" to "2026-12-01 10:30:00", or null. */
    public static function parse(?string $v): ?string
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::\d{2})?$/', $v, $m) || !Clock::valid($m[1]) || (int) $m[2] > 23 || (int) $m[3] > 59) {
            return null;
        }
        return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':00';
    }

    /**
     * Book an interview for a shortlisted (or already interviewing) candidate.
     *
     * @param array<string,mixed> $d starts_at, duration_minutes, location, panel, instructions
     * @return array{id:int,uuid:string,token:string}
     */
    public static function schedule(int $orgId, int $applicationId, array $d, Actor $actor): array
    {
        $a = ApplicationRepo::byId($orgId, $applicationId) ?? throw new DomainException('Application not found.');
        if (!in_array($a['stage'], ['shortlisted', 'interview'], true)) {
            throw new DomainException('Shortlist the candidate before booking an interview.');
        }
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $when = (string) self::parse($d['starts_at']);
        if (substr($when, 0, 10) < Clock::today()) {
            throw new DomainException('The interview cannot be in the past.');
        }
        return DB::transaction(function () use ($orgId, $a, $d, $when, $actor) {
            $uuid = Uuid::v4();
            $token = bin2hex(random_bytes(16));
            $id = DB::insert('interviews', [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'application_id' => $a['id'], 'starts_at' => $when,
                'duration_minutes' => (int) ($d['duration_minutes'] ?? 45), 'location' => trim((string) $d['location']),
                'panel' => self::nullable($d['panel'] ?? null), 'instructions' => self::nullable($d['instructions'] ?? null),
                'public_token' => $token, 'created_by_user_id' => $actor->userId,
            ]);
            if ($a['stage'] === 'shortlisted') {
                ApplicationRepo::setStage($orgId, (int) $a['id'], 'interview', ['interview_date' => substr($when, 0, 10)], $actor);
            } else {
                DB::update('applications', ['interview_date' => substr($when, 0, 10)], ['id' => $a['id']]);
            }
            Audit::record($orgId, $actor, 'interview.scheduled', 'application', $a['uuid'], 'Interview booked for ' . $a['first_name'] . ' ' . $a['last_name'] . ' on ' . substr($when, 0, 16), ['interview' => $uuid]);
            Events::emit($orgId, 'interview.scheduled', $uuid, ['application' => $a['uuid'], 'starts_at' => $when]);
            return ['id' => $id, 'uuid' => $uuid, 'token' => $token];
        });
    }

    /** Move an interview to a new time or place. The candidate's earlier answer no longer applies. @param array<string,mixed> $d */
    public static function reschedule(int $orgId, string $uuid, array $d, Actor $actor): void
    {
        $i = self::byUuid($orgId, $uuid) ?? throw new DomainException('Interview not found.');
        if ($i['status'] !== 'scheduled') {
            throw new DomainException('Only an interview still to happen can be changed.');
        }
        if ($p = self::problems($d)) {
            throw DomainException::of($p);
        }
        $when = (string) self::parse($d['starts_at']);
        if (substr($when, 0, 10) < Clock::today()) {
            throw new DomainException('The interview cannot be in the past.');
        }
        DB::transaction(function () use ($orgId, $i, $d, $when, $actor) {
            DB::update('interviews', [
                'starts_at' => $when, 'duration_minutes' => (int) ($d['duration_minutes'] ?? 45), 'location' => trim((string) $d['location']),
                'panel' => self::nullable($d['panel'] ?? null), 'instructions' => self::nullable($d['instructions'] ?? null),
                'candidate_response' => 'none', 'response_note' => null, 'responded_at' => null,
            ], ['id' => $i['id']]);
            DB::update('applications', ['interview_date' => substr($when, 0, 10)], ['id' => $i['application_id']]);
            $a = ApplicationRepo::byId($orgId, (int) $i['application_id']);
            Audit::record($orgId, $actor, 'interview.rescheduled', 'application', $a['uuid'] ?? null, 'Interview moved to ' . substr($when, 0, 16), ['interview' => $uuid]);
        });
    }

    /** cancelled, completed or no_show. Only an interview still marked scheduled can be closed this way. */
    public static function close(int $orgId, string $uuid, string $status, Actor $actor): void
    {
        $i = self::byUuid($orgId, $uuid) ?? throw new DomainException('Interview not found.');
        if (!in_array($status, ['cancelled', 'completed', 'no_show'], true)) {
            throw new DomainException('That is not something an interview can be marked as.');
        }
        if ($i['status'] !== 'scheduled') {
            throw new DomainException('That interview has already been closed.');
        }
        if ($status !== 'cancelled' && substr((string) $i['starts_at'], 0, 10) > Clock::today()) {
            throw new DomainException('It has not happened yet. Cancel it instead.');
        }
        DB::update('interviews', ['status' => $status], ['id' => $i['id']]);
        $a = ApplicationRepo::byId($orgId, (int) $i['application_id']);
        Audit::record($orgId, $actor, 'interview.' . $status, 'application', $a['uuid'] ?? null, 'Interview ' . str_replace('_', ' ', $status), ['interview' => $uuid]);
    }

    /** The candidate answers from their private link. */
    public static function respond(string $token, string $response, string $note): void
    {
        $i = self::byToken($token) ?? throw new DomainException('That link is not valid.');
        if (!in_array($response, ['confirmed', 'cannot_attend'], true)) {
            throw new DomainException('Choose whether you can come.');
        }
        if ($i['status'] !== 'scheduled' || substr((string) $i['starts_at'], 0, 10) < Clock::today()) {
            throw new DomainException('This interview is no longer open to reply to. Please contact the organisation.');
        }
        if ($m = Check::maxLen($note, 500, 'Your message')) {
            throw new DomainException($m);
        }
        DB::update('interviews', ['candidate_response' => $response, 'response_note' => self::nullable($note), 'responded_at' => gmdate('Y-m-d H:i:s')], ['id' => $i['id']]);
        $a = ApplicationRepo::byId((int) $i['organisation_id'], (int) $i['application_id']);
        Audit::record((int) $i['organisation_id'], Actor::system('candidate'), 'interview.response', 'application', $a['uuid'] ?? null, ($a ? $a['first_name'] . ' ' . $a['last_name'] : 'Candidate') . ($response === 'confirmed' ? ' confirmed the interview' : ' cannot attend the interview'), ['interview' => $i['uuid']]);
        Events::emit((int) $i['organisation_id'], 'interview.response', $i['uuid'], ['response' => $response]);
    }

    /** What staff paste into their own email. Plain text. */
    public static function invitation(array $i, array $a, array $v, string $orgName, string $link): string
    {
        $when = date('l j F Y \a\t H:i', strtotime((string) $i['starts_at']));
        $lines = ['Dear ' . $a['first_name'] . ',', '', 'Thank you for applying for ' . $v['title'] . ' at ' . $orgName . '. We would like to invite you to an interview.', '',
            'When: ' . $when . ' (about ' . (int) $i['duration_minutes'] . ' minutes)', 'Where: ' . $i['location']];
        if ($i['panel']) {
            $lines[] = 'You will meet: ' . $i['panel'];
        }
        if ($i['instructions']) {
            $lines[] = 'Please bring: ' . $i['instructions'];
        }
        return implode("\n", $lines) . "\n\nPlease let us know whether you can come using this private link (no account needed), or reply to this message:\n" . $link . "\n\nKind regards,\n" . $orgName;
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public static function byUuid(int $orgId, string $uuid): ?array
    {
        return Uuid::valid($uuid) ? DB::one('SELECT * FROM interviews WHERE uuid = ? AND organisation_id = ?', [$uuid, $orgId]) : null;
    }

    /** @return array<string,mixed>|null */
    public static function byToken(string $token): ?array
    {
        return preg_match('/^[0-9a-f]{32}$/', $token) ? DB::one('SELECT * FROM interviews WHERE public_token = ?', [$token]) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function forApplication(int $applicationId): array
    {
        return DB::all('SELECT * FROM interviews WHERE application_id = ? ORDER BY starts_at DESC, id DESC', [$applicationId]);
    }

    /** Booked interviews still to come (or today), soonest first. @return list<array<string,mixed>> */
    public static function upcoming(int $orgId, int $limit = 20): array
    {
        return DB::all(
            "SELECT i.*, a.uuid AS application_uuid, a.first_name, a.last_name, v.title AS vacancy_title, v.reference
             FROM interviews i JOIN applications a ON a.id = i.application_id JOIN vacancies v ON v.id = a.vacancy_id
             WHERE i.organisation_id = ? AND i.status = 'scheduled' AND i.starts_at >= ? ORDER BY i.starts_at LIMIT " . (int) $limit,
            [$orgId, Clock::today() . ' 00:00:00']
        );
    }

    private static function nullable(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
