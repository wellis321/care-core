<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\People\PersonRepo;

/** The full staff record, for managers and HR: personal profile, employment terms, and the compliance records. */
final class StaffRecordController extends Controller
{
    public function show(Request $r, array $p): Response
    {
        return $this->render($p['uuid'], []);
    }

    /** Personal profile (address, emergency contact, NI, bank). */
    public function saveProfile(Request $r, array $p): Response
    {
        [$person, $deny] = $this->target($p['uuid']);
        if ($deny) {
            return $deny;
        }
        [$clean, $errors] = StaffRecord::cleanProfile(array_intersect_key($r->post, array_flip(array_keys(array_filter(StaffRecord::FIELDS, static fn($m) => $m['table'] === 'person_profiles')))));
        if ($errors) {
            return $this->render($p['uuid'], ['errors' => $errors, 'old' => $r->post, 'open' => 'profile'], 422);
        }
        StaffRecord::applyProfile($this->orgId(), (int) $person['id'], $clean, $this->actor());
        $this->ok('Personal details saved.');
        return $this->redirect('/people/' . $person['uuid'] . '/record#profile');
    }

    /** One section of employment terms. */
    public function saveSection(Request $r, array $p): Response
    {
        [$person, $deny] = $this->target($p['uuid']);
        if ($deny) {
            return $deny;
        }
        $section = $r->input('section');
        $employment = $this->employment((int) $person['id']);
        if (!$employment || !isset(StaffRecord::SECTIONS[$section])) {
            return Router::error(404, 'Not found', 'There is no employment to record that against.');
        }
        [$clean, $errors] = StaffRecord::cleanSection($section, $r->post);
        if ($errors) {
            return $this->render($p['uuid'], ['errors' => $errors, 'old' => $r->post, 'open' => $section], 422);
        }
        StaffRecord::saveSection($this->orgId(), $employment, $section, $clean, $this->actor());
        $this->ok(StaffRecord::SECTIONS[$section][0] . ' saved.');
        return $this->redirect('/people/' . $person['uuid'] . '/record#' . $section);
    }

    public function addCompliance(Request $r, array $p): Response
    {
        [$person, $deny] = $this->target($p['uuid']);
        if ($deny) {
            return $deny;
        }
        $kind = $p['kind'];
        if (!isset(Compliance::KINDS[$kind])) {
            return Router::error(404, 'Not found', 'That kind of record does not exist.');
        }
        [$clean, $errors] = Compliance::clean($this->orgId(), $kind, $r->post);
        if ($errors) {
            return $this->render($p['uuid'], ['formErrors' => [$kind => $errors], 'old' => $r->post, 'open' => $kind], 422);
        }
        Compliance::add($this->orgId(), $person, $kind, $clean, $this->actor());
        $this->ok('Saved.');
        return $this->redirect('/people/' . $person['uuid'] . '/record#' . $kind);
    }

    public function removeCompliance(Request $r, array $p): Response
    {
        [$person, $deny] = $this->target($p['uuid']);
        if ($deny) {
            return $deny;
        }
        try {
            Compliance::remove($this->orgId(), $person, $p['kind'], $r->input('id'), $this->actor());
            $this->ok('Removed.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/people/' . $person['uuid'] . '/record#' . $p['kind']);
    }

    /** A manager sets a photo or signature directly (for example a scanned signature). */
    public function saveFile(Request $r, array $p): Response
    {
        [$person, $deny] = $this->target($p['uuid']);
        if ($deny) {
            return $deny;
        }
        $field = $r->input('field') === 'signature' ? 'signature_path' : 'photo_path';
        try {
            $path = SelfServiceController::storeUpload($this->orgId(), $field === 'signature_path' ? 'signature' : 'photo', 'file');
            ProfileChanges::setFile($this->orgId(), (int) $person['id'], $field, $path, $field === 'signature_path' ? 'upload' : null, $this->actor());
            $this->ok($field === 'signature_path' ? 'Signature saved.' : 'Photo saved.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/people/' . $person['uuid'] . '/record#profile');
    }

    /** @return array{0:?array<string,mixed>,1:?Response} */
    private function target(string $uuid): array
    {
        $person = PersonRepo::byUuid($this->orgId(), $uuid);
        if (!$person) {
            return [null, Router::error(404, 'Not found', 'That person could not be found.')];
        }
        // Nobody edits their own record here: it would skip the approval that staff requests go through.
        $me = Auth::user();
        if ($me['person_id'] && (int) $me['person_id'] === (int) $person['id'] && $me['role'] !== 'admin') {
            return [null, $this->redirect('/me')];
        }
        return [$person, null];
    }

    /** The employment the terms belong to: the current one, or the latest that ended. @return array<string,mixed>|null */
    private function employment(int $personId): ?array
    {
        return Lifecycle::currentEmployment($personId) ?? DB::one('SELECT * FROM employments WHERE person_id = ? ORDER BY start_date DESC, id DESC LIMIT 1', [$personId]);
    }

    /** @param array<string,mixed> $extra */
    private function render(string $uuid, array $extra, int $status = 200): Response
    {
        [$person, $deny] = $this->target($uuid);
        if ($deny) {
            return $deny;
        }
        $org = $this->orgId();
        $pid = (int) $person['id'];
        $employment = $this->employment($pid);
        $lists = [];
        foreach (array_keys(Compliance::KINDS) as $k) {
            $lists[$k] = Compliance::list($org, $pid, $k);
        }
        return $this->view('staff/record', $extra + [
            'title' => 'Staff record · ' . PersonRepo::displayName($person), 'person' => $person, 'profile' => StaffRecord::profile($pid),
            'employment' => $employment, 'details' => $employment ? StaffRecord::details((int) $employment['id']) : [], 'lists' => $lists,
            'colleagues' => DB::all('SELECT DISTINCT p.uuid, p.first_name, p.last_name FROM employments e JOIN persons p ON p.id = e.person_id WHERE e.organisation_id = ? AND p.id <> ? AND (e.end_date IS NULL OR e.end_date >= CURDATE()) ORDER BY p.last_name, p.first_name', [$org, $pid]),
            'errors' => $extra['errors'] ?? [], 'formErrors' => $extra['formErrors'] ?? [], 'old' => $extra['old'] ?? [], 'open' => $extra['open'] ?? '',
        ], $status);
    }
}
