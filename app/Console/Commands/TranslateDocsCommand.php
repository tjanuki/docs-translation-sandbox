<?php

namespace App\Console\Commands;

use App\Services\TranslateDocsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TranslateDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docs:translate {--source= : Source directory} {--target= : Target directory} {--latest : Only translate files updated in the last 24 hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate markdown documentation files to Japanese using Claude API';

    /**
     * Create a new command instance.
     *
     * @param TranslateDocsService $translateService
     */
    public function __construct(private TranslateDocsService $translateService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $basePath = base_path();
        $sourceDir = $this->option('source') ?: (config('translation.source_dir') ?? 'docs');
        $targetDir = $this->option('target') ?: (config('translation.target_dir') ?? 'jp');
        $docBaseDir = $basePath . '/' . $sourceDir;
        $apiKey = config('translation.claude.api_key');

        // Validate requirements
        if (empty($apiKey)) {
            $this->error('CLAUDE_API_KEY is not set in .env file');
            return 1;
        }

        if (!File::isDirectory($docBaseDir)) {
            $this->error("Source directory {$docBaseDir} does not exist");
            return 1;
        }

        $this->info("Starting translation of Markdown files" .
            ($this->option('latest') ? " updated in the last 24 hours" : "") .
            " from {$sourceDir} to {$targetDir}");

        // Create target directory if it doesn't exist
        $targetBasePath = $docBaseDir . '/' . $targetDir;
        if (!File::isDirectory($targetBasePath)) {
            File::makeDirectory($targetBasePath, 0755, true);
        }

        // Count total translatable files for progress bar
        $finder = File::allFiles($docBaseDir);
        $mdFiles = array_filter($finder, function($file) use ($targetDir) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'md' &&
                !str_contains($file, '/' . $targetDir . '/');
        });

        // Filter by last modified time if --latest option is set
        if ($this->option('latest')) {
            $oneDayAgo = time() - (24 * 60 * 60);
            $mdFiles = array_filter($mdFiles, function($file) use ($oneDayAgo) {
                return filemtime($file) >= $oneDayAgo;
            });
            $this->info("Filtering for files updated in the last 24 hours");
        }

        $totalFiles = count($mdFiles);

        if ($totalFiles === 0) {
            $this->info("No markdown files found" . ($this->option('latest') ? " updated in the last 24 hours" : "") . " in {$sourceDir}");
            return 0;
        }

        $this->info("Found {$totalFiles} markdown files to translate");

        // Create a progress bar
        $progressBar = $this->output->createProgressBar($totalFiles);
        $progressBar->setFormat(
            ' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s%/%estimated:-6s% | %message%'
        );
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        // Set up a callback for the translation service to update progress
        $progressCallback = function($filePath) use ($progressBar) {
            $fileName = basename($filePath);
            $progressBar->setMessage("Translating: {$fileName}");
            $progressBar->advance();
        };

        // Process all files recursively using the service
        $this->translateService->processDirectory(
            $docBaseDir,
            $targetBasePath,
            $targetDir,
            $docBaseDir,
            $progressCallback,
            $this->option('latest')
        );

        $progressBar->finish();
        $this->newLine(2);
        $this->info('Translation of markdown files completed successfully');
        return 0;
    }
}
