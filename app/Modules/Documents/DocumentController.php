<?php
declare(strict_types=1);

namespace App\Modules\Documents;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Controller;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\People\PersonRepo;

/** Staff documents: kept on the staff record, downloaded only by people who manage staff (and by the person, when shared). */
final class DocumentController extends Controller
{
    public function add(Request $r, array $p): Response
    {
        $person = PersonRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$person) {
            return Router::error(404, 'Not found', 'That person could not be found.');
        }
        $me = Auth::user();
        if ($me['person_id'] && (int) $me['person_id'] === (int) $person['id'] && $me['role'] !== 'admin') {
            return Router::error(403, 'Not allowed', 'Ask another manager to add documents to your own record.');
        }
        try {
            DocumentRepo::add($this->orgId(), (int) $person['id'], $_FILES['file'] ?? [], ['title' => $r->input('title'), 'category' => $r->input('category'), 'expires_on' => $r->input('expires_on'), 'shared' => $r->has('shared'), 'notes' => $r->input('notes')], $this->actor());
            $this->ok('Document saved.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/people/' . $person['uuid'] . '/record#documents');
    }

    public function download(Request $r, array $p): Response
    {
        $d = DocumentRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$d || !DocumentRepo::canDownload(Auth::user(), $d, Auth::can('staff.manage'))) {
            return Router::error(404, 'Not found', 'That document could not be found.');
        }
        $bytes = DocumentRepo::read($d);
        if ($bytes === null) {
            return Router::error(404, 'Not found', 'The file for that document is missing.');
        }
        return Response::download($bytes, $d['mime'], $d['original_name']);
    }

    public function remove(Request $r, array $p): Response
    {
        $d = DocumentRepo::byUuid($this->orgId(), $p['uuid']);
        $person = $d ? \App\Core\Database::one('SELECT uuid FROM persons WHERE id = ?', [$d['person_id']]) : null;
        try {
            DocumentRepo::remove($this->orgId(), $p['uuid'], $this->actor());
            $this->ok('Document removed.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect($person ? '/people/' . $person['uuid'] . '/record#documents' : '/documents');
    }

    public function share(Request $r, array $p): Response
    {
        $d = DocumentRepo::byUuid($this->orgId(), $p['uuid']);
        $person = $d ? \App\Core\Database::one('SELECT uuid FROM persons WHERE id = ?', [$d['person_id']]) : null;
        try {
            DocumentRepo::setShared($this->orgId(), $p['uuid'], $r->has('shared'), $this->actor());
            $this->ok($r->has('shared') ? 'They can now see and download it under My profile.' : 'Not shared with them any more.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect($person ? '/people/' . $person['uuid'] . '/record#documents' : '/documents');
    }

    /** Expiring documents and people past the retention period. */
    public function review(Request $r, array $p = []): Response
    {
        $org = $this->orgId();
        return $this->view('documents/review', ['title' => 'Documents to review', 'expiring' => DocumentRepo::expiring($org, 60), 'today' => Clock::today(), 'years' => DocumentRepo::retentionYears($org), 'past' => DocumentRepo::pastRetention($org), 'categories' => DocumentRepo::CATEGORIES]);
    }

    public function retention(Request $r, array $p = []): Response
    {
        try {
            DocumentRepo::setRetentionYears($this->orgId(), $r->input('years'), $this->actor());
            $this->ok('Saved.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/documents#retention');
    }

    /** Everything held about one member of staff, as a file. */
    public function subjectAccess(Request $r, array $p): Response
    {
        $person = PersonRepo::byUuid($this->orgId(), $p['uuid']);
        if (!$person) {
            return Router::error(404, 'Not found', 'That person could not be found.');
        }
        $data = SubjectAccess::build($this->orgId(), (int) $person['id'], $this->actor());
        $name = 'subject-access-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($person['last_name'])) . '-' . Clock::today() . '.json';
        return Response::download((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json', $name);
    }
}
