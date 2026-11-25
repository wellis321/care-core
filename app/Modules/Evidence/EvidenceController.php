<?php
declare(strict_types=1);

namespace App\Modules\Evidence;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Teams\TeamRepo;

/** The staff evidence pack: what is recorded about the staff of a team (or everyone), as at a date. Printable, and a CSV. */
final class EvidenceController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        $asAt = Clock::valid($r->input('as_at')) ? $r->input('as_at') : Clock::today();
        $teamId = null;
        if ($r->input('team') !== '') {
            $t = DB::one('SELECT id FROM teams WHERE uuid = ? AND organisation_id = ?', [$r->input('team'), $org]);
            $teamId = $t ? (int) $t['id'] : null;
        }
        $pack = EvidencePack::build($org, $teamId, $asAt);
        $format = $r->input('format');
        if ($format === 'csv' || $format === 'print') {
            Audit::record($org, $this->actor(), 'evidence.generated', 'organisation', null, 'Staff evidence pack generated (' . ($pack['team']['name'] ?? 'all staff') . ', as at ' . $asAt . ', ' . $format . ')', []);
        }
        if ($format === 'csv') {
            $f = fopen('php://temp', 'w+');
            fwrite($f, "\xEF\xBB\xBF");
            foreach (EvidencePack::csv($pack) as $row) {
                // a cell starting with = + - @ would be read as a formula by a spreadsheet
                fputcsv($f, array_map(static fn($c) => preg_match('/^[=+\-@\t\r]/', (string) $c) ? "'" . $c : (string) $c, $row), ',', '"', '');
            }
            rewind($f); $csv = (string) stream_get_contents($f); fclose($f);
            return Response::download($csv, 'text/csv; charset=UTF-8', 'staff-evidence-' . $asAt . '.csv');
        }
        return $this->view('evidence/index', ['title' => 'Staff evidence pack', 'pack' => $pack, 'teams' => TeamRepo::all($org), 'teamUuid' => $pack['team']['uuid'] ?? '', 'print' => $format === 'print', 'generated' => Clock::today(), 'soon' => EvidencePack::SOON_DAYS]);
    }
}
