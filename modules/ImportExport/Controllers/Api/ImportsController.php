<?php

declare(strict_types=1);

namespace Apotheke\ImportExport\Controllers\Api;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\ImportExport\CsvCells;
use Apotheke\ImportExport\Imports;
use Apotheke\ImportExport\Jobs\ImportCommitJob;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The CSV import API (API plan §3, import permission): POST /api/v1/imports (multipart `file`), GET
 * /api/v1/imports/{id} (the upload, its columns and a sample) and GET /api/v1/imports/{id}/preview (what an import
 * would do, row by row). The preview validates without writing anything (Imports::preview); a mapping given in the
 * query is checked and used, not saved. POST /api/v1/imports/{id}/commit saves the mapping and starts the
 * import.commit job (Imports::commitRows); GET /api/v1/jobs/{id} follows it. The HTML pages are ImportController.
 */
final class ImportsController extends Controller
{
    public const SAMPLE_ROWS = 5;

    public const PER_PAGE = 50;

    public const PER_PAGE_MAX = 200;

    /** POST /api/v1/imports */
    public function create(): ResponseInterface
    {
        $imports = $this->imports();
        $imports->sweep();   // N-374: on every upload, as the page does
        $f = $this->request->getFile('file');
        $file = $f instanceof UploadedFile
            ? ['error' => $f->getError(), 'size' => (int)$f->getSize(), 'tmp_name' => $f->getTempName(), 'name' => $f->getClientName()] : null;
        try {
            $id = $imports->upload($file);
        } catch (UserError $e) {
            return ApiResponse::problem($this->response, 422, 'validation.failed', $e->getMessage(), ['file' => $e->getMessage()]);
        }

        return $this->show((string)$id)->setStatusCode(201)->setHeader('Location', '/api/v1/imports/' . $id);
    }

    /** GET /api/v1/imports/{id} */
    public function show(string $id): ResponseInterface
    {
        $imp = $this->imports()->get((int)$id);
        if ($imp === null || !$this->imports()->mine($imp)) {
            return $this->notFound();
        }
        $columns = [];
        $sample = [];
        $formula = [];
        if ($imp['status'] !== 'done') {
            try {
                [$header, $rows] = $this->imports()->rows($imp);
            } catch (UserError $e) {
                return ApiResponse::problem($this->response, 410, 'import.gone', $e->getMessage());
            }
            foreach ($header as $i => $h) {
                $columns[] = ['index' => $i, 'header' => $h, 'field' => (string)($imp['mapping'][$i] ?? '')];
            }
            $sample = array_slice($rows, 0, self::SAMPLE_ROWS);
            $formula = array_map(self::formulaCells(...), $sample);
        }
        $fields = [];
        foreach (Imports::fields() as $key => [$label]) {
            $fields[] = ['key' => $key, 'label' => $label];
        }

        return ApiResponse::json($this->response, [
            'id' => $imp['id'], 'file_name' => $imp['file_name'], 'status' => $imp['status'], 'total_rows' => $imp['total_rows'],
            'options' => ['create' => !empty($imp['options']['create']), 'skip_errors' => !empty($imp['options']['skip_errors'])],
            'columns' => $columns, 'sample' => $sample, 'sample_formula' => $formula, 'fields' => $fields, 'result' => $imp['result'] === null ? null : (object)$imp['result'],
            'created_at' => $imp['created_at'], 'finished_at' => $imp['finished_at'],
        ]);
    }

    /** GET /api/v1/imports/{id}/preview?map=&create=&skip_errors=&page=&per_page= */
    public function preview(string $id): ResponseInterface
    {
        $imports = $this->imports();
        $imp = $imports->get((int)$id);
        if ($imp === null || !$imports->mine($imp)) {
            return $this->notFound();
        }
        if ($imp['status'] === 'done') {
            return ApiResponse::problem($this->response, 409, 'import.done', 'This file was imported already.');
        }
        try {
            [$header, $rows] = $imports->rows($imp);
        } catch (UserError $e) {
            return ApiResponse::problem($this->response, 410, 'import.gone', $e->getMessage());
        }
        $q = fn (string $k): ?string => is_string($v = $this->request->getGet($k)) ? $v : null;
        $map = $q('map');
        $flag = static fn (?string $v, mixed $stored): bool => $v === null ? !empty($stored) : in_array($v, ['1', 'true'], true);
        try {
            [$imp['mapping'], $imp['options']] = Imports::mapping($header, [
                'col' => $map === null ? $imp['mapping'] : explode(',', $map),
                'create' => $flag($q('create'), $imp['options']['create'] ?? false),
                'skip_errors' => $flag($q('skip_errors'), $imp['options']['skip_errors'] ?? false),
            ]);
        } catch (UserError $e) {
            return ApiResponse::problem($this->response, 422, 'validation.failed', $e->getMessage(), ['map' => $e->getMessage()]);
        }
        $page = max(1, (int)($q('page') ?? 1));
        $per = min(self::PER_PAGE_MAX, max(1, (int)($q('per_page') ?? self::PER_PAGE)));
        $run = $imports->preview($imp);
        if ($run['done_before']) {
            return ApiResponse::problem($this->response, 409, 'import.done', 'This file was imported already.');
        }
        $total = count($run['rows']);
        $base = '/api/v1/imports/' . $imp['id'] . '/preview?' . ($map === null ? '' : 'map=' . rawurlencode(implode(',', $imp['mapping'])) . '&')
            . 'create=' . (int)$imp['options']['create'] . '&skip_errors=' . (int)$imp['options']['skip_errors'] . '&per_page=' . $per . '&page=';
        $links = ['next' => $page * $per < $total ? $base . ($page + 1) : null, 'prev' => $page > 1 ? $base . ($page - 1) : null];
        $response = ApiResponse::json($this->response, [
            'import_id' => $imp['id'], 'mapping' => $imp['mapping'], 'options' => $imp['options'],
            'summary' => ['rows' => $total, 'created' => $run['created'], 'updated' => $run['updated'], 'unchanged' => $run['unchanged'], 'errors' => $run['errors'], 'made' => $run['made']],
            'data' => array_map(static fn (array $row): array => $row + ['formula' => self::formulaCells($rows[$row['line'] - 2] ?? [])],
                array_slice($run['rows'], ($page - 1) * $per, $per)),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total], 'links' => $links,
        ]);
        $link = implode(', ', array_filter([$links['next'] !== null ? '<' . $links['next'] . '>; rel="next"' : null, $links['prev'] !== null ? '<' . $links['prev'] . '>; rel="prev"' : null]));

        return $link === '' ? $response : $response->setHeader('Link', $link);
    }

    /**
     * L-1361: the indexes of the cells a spreadsheet would run as a formula (CsvCells::formulaLike(), as the export
     * guards them). The values stay raw, as they are imported; a client that writes them to a CSV must neutralise them.
     *
     * @param list<string> $cells
     * @return list<int>
     */
    private static function formulaCells(array $cells): array
    {
        return array_keys(array_filter($cells, CsvCells::formulaLike(...)));
    }

    /** POST /api/v1/imports/{id}/commit with an optional {"map": [...], "create": bool, "skip_errors": bool} */
    public function commit(string $id): ResponseInterface
    {
        $imports = $this->imports();
        $imp = $imports->get((int)$id);
        if ($imp === null) {
            return $this->notFound();
        }
        if ($imp['status'] === 'done') {
            return ApiResponse::problem($this->response, 409, 'import.done', 'This file was imported already.');
        }
        /** @var JobQueue $queue */
        $queue = service('jobQueue');
        $payload = ['import_id' => $imp['id']];
        $running = $queue->active(ImportCommitJob::TYPE, $payload);
        if ($running !== null) {   // a second submit follows the first one's job
            return $this->accepted($running->view());
        }
        try {
            [$header] = $imports->rows($imp);
        } catch (UserError $e) {
            return ApiResponse::problem($this->response, 410, 'import.gone', $e->getMessage());
        }
        $raw = (string)$this->request->getBody();
        try {
            $body = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ApiResponse::problem($this->response, 400, 'bad_request', 'The body is not valid JSON.');
        }
        $body = is_array($body) ? $body : [];
        try {
            [$imp['mapping'], $imp['options']] = $imports->setMapping($imp['id'], $header, [
                'col' => array_key_exists('map', $body) ? $body['map'] : $imp['mapping'],
                'create' => array_key_exists('create', $body) ? $body['create'] === true : !empty($imp['options']['create']),
                'skip_errors' => array_key_exists('skip_errors', $body) ? $body['skip_errors'] === true : !empty($imp['options']['skip_errors']),
            ]);
        } catch (UserError $e) {
            return ApiResponse::problem($this->response, 422, 'validation.failed', $e->getMessage(), ['map' => $e->getMessage()]);
        }
        $run = $imports->preview($imp);
        if ($run['errors'] > 0 && !$imp['options']['skip_errors']) {
            $msg = $run['errors'] . ' ' . ($run['errors'] === 1 ? 'row has' : 'rows have') . ' errors. Fix the file, or set skip_errors.';

            return ApiResponse::problem($this->response, 422, 'validation.failed', $msg, ['rows' => $msg]);
        }
        $job = $queue->find($queue->enqueue(ImportCommitJob::TYPE, $payload, $imports->actor()));

        return $this->accepted($job?->view());
    }

    /** @param array<string,mixed>|null $job */
    private function accepted(?array $job): ResponseInterface
    {
        return ApiResponse::json($this->response, $job, 202)->setHeader('Location', '/api/v1/jobs/' . (int)($job['id'] ?? 0));
    }

    private function notFound(): ResponseInterface
    {
        return ApiResponse::problem($this->response, 404, 'not_found', 'Import not found.');
    }

    private function imports(): Imports
    {
        /** @var Imports $i */
        $i = service('imports');

        return $i;
    }
}
