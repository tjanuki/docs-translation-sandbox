<?php

namespace App\Console\Commands;

use App\Services\TranslateDocsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

class TranslateDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docs:translate {--source= : Source directory} {--target= : Target directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate documentation files to Japanese using Claude API';

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

        $this->info("Starting translation of files from {$sourceDir} to {$targetDir}");

        // Create target directory if it doesn't exist
        $targetBasePath = $docBaseDir . '/' . $targetDir;
        if (!File::isDirectory($targetBasePath)) {
            File::makeDirectory($targetBasePath, 0755, true);
        }

        // Count total translatable files for progress bar
        $supportedExtensions = config('translation.supported_extensions', ['md', 'txt', 'html']);
        $finder = new Finder();
        $finder->files()
            ->in($docBaseDir)
            ->name(array_map(function($ext) { return "*.{$ext}"; }, $supportedExtensions))
            ->notPath($targetDir);

        $totalFiles = iterator_count($finder);

        if ($totalFiles === 0) {
            $this->info("No translatable files found in {$sourceDir}");
            return 0;
        }

        $this->info("Found {$totalFiles} files to translate");

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
            $progressCallback
        );

        $progressBar->finish();
        $this->newLine(2);
        $this->info('Translation completed successfully');
        return 0;
    }
}
