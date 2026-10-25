<?php

namespace App\Console\Commands;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Project;
use App\Services\IncidentImport\IncidentReportParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports incident reports written as Markdown files (for example kept in a
 * project's repository). Copy the files into storage/app/import/<repo>/
 * first: the container does not see other repositories. Running it twice
 * imports nothing twice.
 */
class ImportIncidents extends Command
{
    protected $signature = 'incidents:import
        {directory : Directory with the *.md reports (not recursive)}
        {--source= : The repository the reports come from, e.g. acme-platform (required: it keeps the import keys of different repositories apart)}
        {--project= : Slug of the project to link every imported incident to}
        {--dry-run : Show what would be imported, write nothing}';

    protected $description = 'Import incident reports (markdown) from a directory';

    public function handle(IncidentReportParser $parser): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/');

        if (! is_dir($directory)) {
            $this->components->error("Not a directory: {$directory}");

            return self::FAILURE;
        }

        $source = trim((string) $this->option('source'));

        if ($source === '') {
            $this->components->error('Pass --source=<repository>: it keeps reports with the same file name in different repositories apart.');

            return self::FAILURE;
        }

        $project = null;

        if (filled($this->option('project'))) {
            $project = Project::query()->where('slug', $this->option('project'))->first();

            if ($project === null) {
                $this->components->error("No project with slug [{$this->option('project')}].");

                return self::FAILURE;
            }
        }

        $files = glob($directory.'/*.md') ?: [];
        sort($files);
        $rows = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $key = "{$source}/{$filename}";

            if (mb_strlen($key) > 255) {
                $rows[] = [$filename, '', '', '', 'skipped: source + file name longer than 255 characters'];

                continue;
            }

            if (Incident::query()->where('imported_from', $key)->exists()) {
                $rows[] = [$filename, '', '', '', 'skipped: already imported'];

                continue;
            }

            try {
                $parsed = $parser->parse($filename, (string) file_get_contents($file));
            } catch (InvalidArgumentException $exception) {
                $rows[] = [$filename, '', '', '', "skipped: {$exception->getMessage()}; rename the copy to YYYY-MM-DD-{$filename}"];

                continue;
            }

            if (! $this->option('dry-run')) {
                DB::transaction(function () use ($parsed, $key, $project): void {
                    $incident = Incident::create([
                        'title' => $parsed->title,
                        'severity' => IncidentSeverity::Sev2,
                        'status' => IncidentStatus::Resolved,
                        'occurred_on' => $parsed->occurredOn,
                        'impact' => $parsed->impact,
                        'body' => $parsed->body,
                        'source' => IncidentSource::Import,
                        'imported_from' => $key,
                    ]);

                    $project?->incidents()->attach($incident);
                });
            }

            $rows[] = [$filename, $parsed->title, $parsed->occurredOn, $parsed->impact === null ? 'no' : 'yes', $this->option('dry-run') ? 'would import' : 'imported'];
        }

        $this->table(['File', 'Title', 'Date', 'Impact', 'Result'], $rows);

        if (! $this->option('dry-run')) {
            $this->components->info('Imported incidents get severity SEV2 and status resolved: check them in the panel.');
        }

        return self::SUCCESS;
    }
}
