<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
     * The base path for documentation.
     */
    protected string $basePath;

    /**
     * The source directory.
     */
    protected string $sourceDir;

    /**
     * The target directory for translated files.
     */
    protected string $targetDir;

    /**
     * The document base directory - used as a consistent reference point.
     */
    protected string $docBaseDir;

    /**
     * The Claude API key.
     */
    protected string $apiKey;

    /**
     * The Claude API URL.
     */
    protected string $apiUrl;

    /**
     * The Claude model to use.
     */
    protected string $model;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->basePath = base_path();
        $this->sourceDir = $this->option('source') ?: (config('translation.source_dir') ?? 'docs');
        $this->targetDir = $this->option('target') ?: (config('translation.target_dir') ?? 'jp');
        $this->docBaseDir = $this->basePath . '/' . $this->sourceDir;
        $this->apiKey = config('translation.claude.api_key') ?? env('CLAUDE_API_KEY');
        $this->apiUrl = config('translation.claude.api_url') ?? 'https://api.anthropic.com/v1/messages';
        $this->model = config('translation.claude.model') ?? env('CLAUDE_MODEL', 'claude-3-5-sonnet-20240620');

        if (empty($this->apiKey)) {
            $this->error('CLAUDE_API_KEY is not set in .env file');
            return 1;
        }

        if (!File::isDirectory($this->docBaseDir)) {
            $this->error("Source directory {$this->docBaseDir} does not exist");
            return 1;
        }

        $this->info("Starting translation of files from {$this->sourceDir} to {$this->targetDir}");

        // Create target directory if it doesn't exist
        $targetBasePath = $this->docBaseDir . '/' . $this->targetDir;
        if (!File::isDirectory($targetBasePath)) {
            File::makeDirectory($targetBasePath, 0755, true);
        }

        // Process all files recursively
        $this->processDirectory($this->docBaseDir, $targetBasePath);

        $this->info('Translation completed successfully');
        return 0;
    }

    /**
     * Process all files in a directory recursively.
     */
    protected function processDirectory(string $sourcePath, string $targetPath): void
    {
        $files = File::files($sourcePath);

        foreach ($files as $file) {
            // Skip if the file path contains the target directory
            if (str_contains($file->getPathname(), '/' . $this->targetDir . '/')) {
                continue;
            }

            // Get the path relative to the document base directory
            $relativePath = $this->getRelativePath($file->getPathname(), $this->docBaseDir);
            $targetFilePath = $targetPath . '/' . $relativePath;

            // Create directory if it doesn't exist
            $targetFileDir = dirname($targetFilePath);
            if (!File::isDirectory($targetFileDir)) {
                File::makeDirectory($targetFileDir, 0755, true);
            }

            $this->translateFile($file->getPathname(), $targetFilePath);
        }

        // Process subdirectories
        $directories = File::directories($sourcePath);

        foreach ($directories as $directory) {
            // Skip the target directory itself
            if (basename($directory) === $this->targetDir) {
                continue;
            }

            // Get the subdirectory path relative to the source directory
            $relativeDir = $this->getRelativePath($directory, $this->docBaseDir);
            $newTargetPath = $targetPath . '/' . $relativeDir;

            // Create directory if it doesn't exist
            if (!File::isDirectory($newTargetPath)) {
                File::makeDirectory($newTargetPath, 0755, true);
            }

            // Process files in the subdirectory - pass the current directory and its target
            $this->processFilesInDirectory($directory, $newTargetPath);
        }
    }

    /**
     * Process just the files in a directory without recursion.
     */
    protected function processFilesInDirectory(string $sourcePath, string $targetPath): void
    {
        $files = File::files($sourcePath);

        foreach ($files as $file) {
            // Skip if the file path contains the target directory
            if (str_contains($file->getPathname(), '/' . $this->targetDir . '/')) {
                continue;
            }

            // The filename only, not the relative path
            $filename = basename($file->getPathname());
            $targetFilePath = $targetPath . '/' . $filename;

            $this->translateFile($file->getPathname(), $targetFilePath);
        }

        // Process subdirectories
        $directories = File::directories($sourcePath);

        foreach ($directories as $directory) {
            // Skip the target directory itself
            if (basename($directory) === $this->targetDir) {
                continue;
            }

            // Create the subdirectory in the target
            $subdirName = basename($directory);
            $newTargetPath = $targetPath . '/' . $subdirName;

            if (!File::isDirectory($newTargetPath)) {
                File::makeDirectory($newTargetPath, 0755, true);
            }

            // Process files in this subdirectory
            $this->processFilesInDirectory($directory, $newTargetPath);
        }
    }

    /**
     * Get relative path from a base path.
     */
    protected function getRelativePath(string $path, string $basePath): string
    {
        return ltrim(str_replace($basePath, '', $path), '/');
    }

    /**
     * Translate a file and save it to the target path.
     */
    protected function translateFile(string $sourcePath, string $targetPath): void
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);

        // Skip non-text files or files that should not be translated
        if (!in_array($extension, ['md', 'txt', 'html'])) {
            $this->warn("Skipping file with unsupported extension: {$sourcePath}");
            return;
        }

        $this->info("Translating: {$sourcePath}");

        try {
            $content = File::get($sourcePath);

            // Skip empty files
            if (empty(trim($content))) {
                $this->warn("Skipping empty file: {$sourcePath}");
                File::put($targetPath, '');
                return;
            }

            // Check if the file is large and needs chunking
            if (strlen($content) > 10000) {
                $this->info("File is large, translating in chunks...");
                $translatedContent = $this->translateLargeContent($content, $extension);
            } else {
                $translatedContent = $this->translateContent($content, $extension);
            }

            if ($translatedContent) {
                File::put($targetPath, $translatedContent);
                $this->info("Successfully translated and saved to: {$targetPath}");
            } else {
                $this->error("Failed to translate: {$sourcePath}");
                // Create an empty file or copy the original as a fallback
                File::put($targetPath, "<!-- Translation failed for this file -->\n" . $content);
            }
        } catch (\Exception $e) {
            $this->error("Error processing file {$sourcePath}: " . $e->getMessage());
            Log::error("Translation error for {$sourcePath}: " . $e->getMessage());
        }
    }

    /**
     * Translate large content by breaking it into chunks.
     */
    protected function translateLargeContent(string $content, string $fileType): ?string
    {
        $this->info("Breaking content into chunks for translation...");

        // For Markdown, try to split at heading boundaries
        if ($fileType === 'md') {
            $chunks = $this->splitMarkdownContentIntoChunks($content);
        } else {
            // For other file types, split by paragraph or reasonable length
            $chunks = $this->splitContentIntoChunks($content);
        }

        $this->info("Content divided into " . count($chunks) . " chunks");

        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            $this->info("Translating chunk " . ($index + 1) . " of " . count($chunks));

            $translatedChunk = $this->translateContent($chunk, $fileType);

            if ($translatedChunk) {
                $translatedChunks[] = $translatedChunk;
            } else {
                $this->error("Failed to translate chunk " . ($index + 1));
                // Add the original chunk as a fallback
                $translatedChunks[] = "<!-- Translation failed for this chunk -->\n" . $chunk;
            }

            // Add a small delay between API calls to avoid rate limiting
            if ($index < count($chunks) - 1) {
                sleep(1);
            }
        }

        return implode("\n\n", $translatedChunks);
    }

    /**
     * Split markdown content into logical chunks based on headings.
     */
    protected function splitMarkdownContentIntoChunks(string $content, int $maxChunkSize = 8000): array
    {
        // Try to split at heading boundaries
        $pattern = '/^#{1,6}\s+.+$/m';
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        $sections = [];
        $headingPositions = array_column($matches[0], 1);

        // Add the start of the file
        array_unshift($headingPositions, 0);

        // Process each section
        for ($i = 0; $i < count($headingPositions); $i++) {
            $start = $headingPositions[$i];
            $end = isset($headingPositions[$i + 1]) ? $headingPositions[$i + 1] : strlen($content);

            $sectionContent = substr($content, $start, $end - $start);

            // If the section is still too large, break it down further
            if (strlen($sectionContent) > $maxChunkSize) {
                $subChunks = $this->splitContentIntoChunks($sectionContent, $maxChunkSize);
                $sections = array_merge($sections, $subChunks);
            } else {
                $sections[] = $sectionContent;
            }
        }

        // Handle the case where no headings were found
        if (empty($sections)) {
            return $this->splitContentIntoChunks($content, $maxChunkSize);
        }

        return $sections;
    }

    /**
     * Split any content into chunks based on size.
     */
    protected function splitContentIntoChunks(string $content, int $maxChunkSize = 8000): array
    {
        // Try to split at paragraph boundaries
        $paragraphs = preg_split('/\n\s*\n/', $content);

        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            // If adding this paragraph would exceed the chunk size, start a new chunk
            if (strlen($currentChunk) + strlen($paragraph) + 2 > $maxChunkSize && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = $paragraph;
            } else {
                $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $paragraph;
            }
        }

        // Add the last chunk if it's not empty
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        // If a single paragraph is too large, we need to split it
        foreach ($chunks as $i => $chunk) {
            if (strlen($chunk) > $maxChunkSize) {
                // Replace with smaller chunks
                $subChunks = $this->forceSplitContent($chunk, $maxChunkSize);
                array_splice($chunks, $i, 1, $subChunks);
            }
        }

        return $chunks;
    }

    /**
     * Force split content into chunks regardless of structure.
     */
    protected function forceSplitContent(string $content, int $maxChunkSize = 8000): array
    {
        $chunks = [];
        $remaining = $content;

        while (strlen($remaining) > 0) {
            $chunkSize = min(strlen($remaining), $maxChunkSize);

            // Try to find a sentence or line break near the chunk size
            $breakPoint = $chunkSize;

            // Search for a period, question mark, or exclamation point followed by whitespace
            if ($chunkSize < strlen($remaining)) {
                for ($i = $chunkSize - 1; $i >= $chunkSize - 200 && $i > 0; $i--) {
                    if (preg_match('/[.!?]\s/', substr($remaining, $i, 2))) {
                        $breakPoint = $i + 1;
                        break;
                    }
                }

                // If no sentence break found, try a newline
                if ($breakPoint === $chunkSize) {
                    $newlinePos = strrpos(substr($remaining, 0, $chunkSize), "\n");
                    if ($newlinePos !== false && $newlinePos > $chunkSize - 200) {
                        $breakPoint = $newlinePos + 1;
                    }
                }
            }

            $chunks[] = substr($remaining, 0, $breakPoint);
            $remaining = substr($remaining, $breakPoint);
        }

        return $chunks;
    }

    /**
     * Translate content using Claude API.
     */
    protected function translateContent(string $content, string $fileType): ?string
    {
        $prompt = $this->buildTranslationPrompt($content, $fileType);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post($this->apiUrl, [
                'model' => $this->model,
                'max_tokens' => 8192,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['content'][0]['text'] ?? null;
            } else {
                $this->error("API error: " . $response->status() . " - " . json_encode($response->json()));
                Log::error("Claude API error: " . json_encode($response->json()));
                return null;
            }
        } catch (\Exception $e) {
            $this->error("API request failed: " . $e->getMessage());
            Log::error("Claude API request failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build translation prompt based on content type.
     */
    protected function buildTranslationPrompt(string $content, string $fileType): string
    {
        if ($fileType === 'md') {
            $instructions = <<<PROMPT
I need this Markdown document translated to Japanese.

IMPORTANT INSTRUCTIONS:
1. Translate ONLY the text content
2. Preserve ALL Markdown formatting, code blocks, links, and syntax exactly as is
3. DO NOT add any introduction or commentary like "Here's the translation"
4. Start your response with the first translated character of the document
5. Format the output exactly like the input, just with Japanese text
6. Preserve all code examples unchanged

Here's the document to translate:

PROMPT;
        } elseif ($fileType === 'html') {
            $instructions = <<<PROMPT
I need this HTML document translated to Japanese.

IMPORTANT INSTRUCTIONS:
1. Translate ONLY the text content inside tags
2. Preserve ALL HTML tags, attributes, and structure exactly as is
3. DO NOT add any introduction or commentary like "Here's the translation"
4. Start your response with the first character of the document
5. Format the output exactly like the input, just with Japanese text
6. Preserve all code examples unchanged

Here's the document to translate:

PROMPT;
        } else {
            $instructions = <<<PROMPT
I need this text document translated to Japanese.

IMPORTANT INSTRUCTIONS:
1. Translate ONLY the text content
2. Preserve ALL original formatting and structure exactly as is
3. DO NOT add any introduction or commentary like "Here's the translation"
4. Start your response with the first translated character of the document
5. Format the output exactly like the input, just with Japanese text
6. Preserve all code examples unchanged

Here's the document to translate:

PROMPT;
        }

        return $instructions . "\n\n" . $content;
    }
}
