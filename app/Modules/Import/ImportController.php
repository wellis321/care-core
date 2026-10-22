<?php
declare(strict_types=1);

namespace App\Modules\Import;

use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;

/** Admin › Import: upload bundles, see exactly what would happen, then do it. */
final class ImportController extends Controller
{
    private const MAX_FILES = 6;

    public function index(Request $r, array $p = []): Response
    {
        return $this->view('admin/import', ['title' => 'Import data', 'runs' => DB::all('SELECT * FROM import_runs WHERE organisation_id = ? ORDER BY id DESC LIMIT 15', [$this->orgId()])]);
    }

    /** Dry run: parse and merge the uploaded files, run the importer without keeping anything, and show the report. */
    public function preview(Request $r, array $p = []): Response
    {
        try {
            $files = $_FILES['bundles'] ?? null;
            $names = is_array($files['name'] ?? null) ? array_values(array_filter($files['name'], static fn($n) => $n !== '')) : [];
            if (!$names) {
                throw new DomainException('Choose at least one import file.');
            }
            if (count($names) > self::MAX_FILES) {
                throw new DomainException('Upload at most ' . self::MAX_FILES . ' files at once.');
            }
            $bundles = [];
            foreach ($files['tmp_name'] as $i => $tmp) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $tmp)) {
                    throw new DomainException('“' . $files['name'][$i] . '” could not be uploaded. It may be larger than this server allows: use the command-line importer for big files.');
                }
                try {
                    $bundles[] = Bundle::parse((string) file_get_contents($tmp));
                } catch (DomainException $e) {
                    throw new DomainException('“' . $files['name'][$i] . '”: ' . $e->getMessage());
                }
            }
            $bundle = Bundle::merge($bundles);
            $label = implode(', ', array_map('basename', $names));
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
            return $this->redirect('/admin/import');
        }
        $token = bin2hex(random_bytes(16));
        $dir = ROOT_PATH . '/storage/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $this->fail('The import could not be prepared (the storage folder is not writable).');
            return $this->redirect('/admin/import');
        }
        file_put_contents("$dir/$token.json", json_encode(['label' => $label, 'org' => $this->orgId(), 'bundle' => $bundle]), LOCK_EX);
        $report = (new Importer($this->orgId(), true, false))->run($bundle, $label);
        return $this->view('admin/import-report', ['title' => 'Import: what would happen', 'report' => $report, 'bundle' => $bundle, 'token' => $token, 'label' => $label, 'done' => false]);
    }

    /** The real thing, on the same prepared bundle. */
    public function commit(Request $r, array $p = []): Response
    {
        $token = $r->input('token');
        $file = ROOT_PATH . '/storage/imports/' . $token . '.json';
        $saved = preg_match('/^[0-9a-f]{32}$/', $token) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($saved) || (int) ($saved['org'] ?? 0) !== $this->orgId()) {
            $this->fail('That import is no longer available. Upload the files again.');
            return $this->redirect('/admin/import');
        }
        $skip = $r->input('skip_errors') === '1';
        $report = (new Importer($this->orgId(), false, $skip))->run($saved['bundle'], (string) $saved['label']);
        @unlink($file);
        return $this->view('admin/import-report', ['title' => 'Import result', 'report' => $report, 'bundle' => $saved['bundle'], 'token' => '', 'label' => $saved['label'], 'done' => true]);
    }
}
