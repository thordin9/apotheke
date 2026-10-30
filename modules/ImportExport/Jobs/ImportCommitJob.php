<?php

declare(strict_types=1);

namespace Apotheke\ImportExport\Jobs;

use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobContext;
use Apotheke\Core\Jobs\JobFailed;
use Apotheke\Core\Jobs\JobHandler;
use Apotheke\ImportExport\Imports;

/** The import.commit job (POST /api/v1/imports/{id}/commit): Imports::commitRows() for the import in its payload. */
final class ImportCommitJob implements JobHandler
{
    public const TYPE = 'import.commit';

    public function __construct(private readonly Imports $imports)
    {
    }

    public function run(Job $job, JobContext $context): array
    {
        $imp = $this->imports->get((int)($job->payload['import_id'] ?? 0));
        if ($imp === null) {
            throw new JobFailed('Import not found.');
        }

        return $this->imports->commitRows($imp, $context);
    }
}
