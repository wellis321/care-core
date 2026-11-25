<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Ctx;
use App\Api\Page;
use App\Core\Clock;

/**
 * Who is away, and when. For a rota: it says a person is not available, never why. The kind of absence (annual leave, sickness, other) is health and
 * personal information and is not part of this answer. Only approved leave and recorded sickness are listed, never a request still waiting.
 */
final class AbsencesApi extends BaseApi
{
    public const TAG = 'Away';

    public static function routes(): array
    {
        return [
            self::route('GET', '/away', 'staff.read', 'index', 'Who is away between two dates', self::paging() + [
                'from' => ['date', 'First day to look at. Required.'], 'to' => ['date', 'Last day to look at. Required. At most 92 days after from.'],
                'person_id' => ['uuid', 'Only this person.'],
            ], [], 'list:Away', 200, 'Approved leave and recorded sickness only. The kind is left out on purpose. An open-ended absence has no ends_on.'),
        ];
    }

    public function index(Ctx $c): array
    {
        $from = $c->qDate('from');
        $to = $c->qDate('to');
        if ($from === null || $to === null) {
            throw ApiException::badRequest('from and to are required dates (YYYY-MM-DD).');
        }
        if ($to < $from || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 92) {
            throw ApiException::badRequest('to must be on or after from, and at most 92 days later.');
        }
        $sql = "SELECT a.id, a.uuid, a.starts_on, a.ends_on, a.start_half, a.end_half, p.uuid AS person_uuid FROM absences a JOIN persons p ON p.id = a.person_id
                WHERE a.organisation_id = ? AND a.status = 'approved' AND a.starts_on <= ? AND (a.ends_on IS NULL OR a.ends_on >= ?)";
        $params = [$c->org(), $to, $from];
        if ($pid = $c->q('person_id')) {
            $sql .= ' AND a.person_id = ?';
            $params[] = (int) $c->person($pid)['id'];
        }
        return Page::run($c, $sql, $params, 'a.id', static fn(array $r): array => [
            'id' => $r['uuid'], 'person_id' => $r['person_uuid'], 'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'],
            'first_day_half' => (bool) $r['start_half'], 'last_day_half' => (bool) $r['end_half'],
        ]);
    }
}
