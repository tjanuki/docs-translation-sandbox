<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TranslateDocsService
{
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
     * Create a new TranslateDocsService instance.
     */
    public function __construct()
    {
        $this->apiKey = config('translation.claude.api_key');
        $this->apiUrl = config('translation.claude.api_url');
        $this->model = config('translation.claude.model');
    }

    /**
     * Process all markdown files in a directory recursively.
     *
     * @param string $sourcePath The source directory path
     * @param string $targetPath The target directory path
     * @param string $targetDir The name of the target directory
     * @param string $docBaseDir The base directory for documents
     * @param callable|null $progressCallback Optional callback for progress updates
     * @param bool $latestOnly Only process files updated in the last 24 hours
     */
    public function processDirectory(
        string $sourcePath,
        string $targetPath,
        string $targetDir,
        string $docBaseDir,
        ?callable $progressCallback = null,
        bool $latestOnly = false
    ): void {
        $files = File::files($sourcePath);

        foreach ($files as $file) {
            // Skip if the file path contains the target directory
            if (str_contains($file->getPathname(), '/' . $targetDir . '/')) {
                continue;
            }

            // Skip non-markdown files
            if (pathinfo($file->getPathname(), PATHINFO_EXTENSION) !== 'md') {
                continue;
            }

            // Skip files not modified in the last 24 hours if latestOnly is true
            if ($latestOnly) {
                $oneDayAgo = time() - (24 * 60 * 60);
                if (filemtime($file->getPathname()) < $oneDayAgo) {
                    continue;
                }
            }

            // Get the path relative to the document base directory
            $relativePath = $this->getRelativePath($file->getPathname(), $docBaseDir);
            $targetFilePath = $targetPath . '/' . $relativePath;

            // Create directory if it doesn't exist
            $targetFileDir = dirname($targetFilePath);
            if (!File::isDirectory($targetFileDir)) {
                File::makeDirectory($targetFileDir, 0755, true);
            }

            $result = $this->translateFile($file->getPathname(), $targetFilePath);

            // Call the progress callback if provided
            if ($progressCallback) {
                call_user_func($progressCallback, $file->getPathname(), $result);
            }
        }

        // Process subdirectories
        $directories = File::directories($sourcePath);

        foreach ($directories as $directory) {
            // Skip the target directory itself
            if (basename($directory) === $targetDir) {
                continue;
            }

            // Get the subdirectory path relative to the source directory
            $relativeDir = $this->getRelativePath($directory, $docBaseDir);
            $newTargetPath = $targetPath . '/' . $relativeDir;

            // Create directory if it doesn't exist
            if (!File::isDirectory($newTargetPath)) {
                File::makeDirectory($newTargetPath, 0755, true);
            }

            // Process files in the subdirectory recursively
            $this->processDirectory($directory, $newTargetPath, $targetDir, $docBaseDir, $progressCallback, $latestOnly);
        }
    }

    /**
     * Get relative path from a base path.
     *
     * @param string $path The full path
     * @param string $basePath The base path to remove
     *
     * @return string The relative path
     */
    public function getRelativePath(string $path, string $basePath): string
    {
        return ltrim(str_replace($basePath, '', $path), '/');
    }

    /**
     * Translate a markdown file and save it to the target path.
     *
     * @param string $sourcePath The source file path
     * @param string $targetPath The target file path
     * @return mixed true for success, false for failure, null for skipped
     */
    public function translateFile(string $sourcePath, string $targetPath): ?bool
    {
        Log::info("Translating: {$sourcePath}");

        try {
            $content = File::get($sourcePath);

            // Skip empty files
            if (empty(trim($content))) {
                Log::warning("Skipping empty file: {$sourcePath}");
                File::put($targetPath, '');
                return null; // Null indicates skipped
            }

            // Check if the file is large and needs chunking
            $largeFileThreshold = config('translation.chunking.large_file_threshold', 10000);
            if (strlen($content) > $largeFileThreshold) {
                Log::info("File is large, translating in chunks...");
                $translatedContent = $this->translateLargeContent($content);
            } else {
                $translatedContent = $this->translateContent($content);
            }

            if ($translatedContent) {
                File::put($targetPath, $translatedContent);
                Log::info("Successfully translated and saved to: {$targetPath}");
                return true;
            } else {
                Log::error("Failed to translate: {$sourcePath}");
                // Create an empty file or copy the original as a fallback
                File::put($targetPath, "<!-- Translation failed for this file -->\n" . $content);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error processing file {$sourcePath}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Translate large markdown content by breaking it into chunks.
     *
     * @param string $content The content to translate
     *
     * @return string|null The translated content or null on failure
     */
    public function translateLargeContent(string $content): ?string
    {
        Log::info("Breaking content into chunks for translation...");

        // Split at heading boundaries for markdown
        $chunks = $this->splitMarkdownContentIntoChunks($content);

        Log::info("Content divided into " . count($chunks) . " chunks");

        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            Log::info("Translating chunk " . ($index + 1) . " of " . count($chunks));

            $translatedChunk = $this->translateContent($chunk);

            if ($translatedChunk) {
                $translatedChunks[] = $translatedChunk;
            } else {
                Log::error("Failed to translate chunk " . ($index + 1));
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
     *
     * @param string $content The markdown content
     * @param int $maxChunkSize The maximum chunk size in characters
     *
     * @return array The content split into chunks
     */
    public function splitMarkdownContentIntoChunks(string $content, int $maxChunkSize = 8000): array
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
     * Split content into chunks based on size.
     *
     * @param string $content The content to split
     * @param int $maxChunkSize The maximum chunk size in characters
     *
     * @return array The content split into chunks
     */
    public function splitContentIntoChunks(string $content, int $maxChunkSize = 8000): array
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

        return $chunks;
    }

    /**
     * Translate content using Claude API.
     *
     * @param string $content The content to translate
     *
     * @return string|null The translated content or null on failure
     */
    public function translateContent(string $content): ?string
    {
        $prompt = $this->buildTranslationPrompt($content);

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
                Log::error("Claude API error: " . json_encode($response->json()));
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Claude API request failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build translation prompt for markdown.
     *
     * @param string $content The content to translate
     *
     * @return string The translation prompt
     */
    public function buildTranslationPrompt(string $content): string
    {
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

        return $instructions . "\n\n" . $content;
    }
}
