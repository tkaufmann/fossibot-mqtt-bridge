<?php

declare(strict_types=1);

namespace Fossibot\Bridge;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use React\EventLoop\LoopInterface;
use React\Stream\WritableResourceStream;
use Exception;

/**
 * Non-blocking async file handler for Monolog using ReactPHP.
 *
 * Writes log entries to a file using ReactPHP's WritableResourceStream to avoid
 * blocking the event loop. Implements daily log rotation with configurable retention.
 * Buffers logs when the stream is unavailable to prevent data loss.
 */
class AsyncFileHandler extends AbstractProcessingHandler
{
    private LoopInterface $loop;
    private string $logFile;
    private ?WritableResourceStream $stream = null;
    private int $maxFiles;
    private int $currentDay;

    // Buffer for when stream is not ready
    private array $writeBuffer = [];
    private const MAX_BUFFER_SIZE = 1000;

    public function __construct(
        LoopInterface $loop,
        string $logFile,
        int $maxFiles = 7,
        int|string|Level $level = Level::Debug
    ) {
        parent::__construct($level);
        $this->loop = $loop;
        $this->logFile = $logFile;
        $this->maxFiles = $maxFiles;
        $this->currentDay = (int) date('d');

        $this->openStream();
    }

    protected function write(LogRecord $record): void
    {
        // Check if rotation needed (daily)
        $this->checkRotation();

        $formatted = (string) $record->formatted;

        if ($this->stream === null || !$this->stream->isWritable()) {
            // Stream not ready - buffer the log
            $this->bufferWrite($formatted);
            return;
        }

        // Non-blocking write via ReactPHP stream
        $this->stream->write($formatted);
    }

    private function openStream(): void
    {
        // Ensure directory exists
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Open file in append mode
        $resource = @fopen($this->logFile, 'a');

        if ($resource === false) {
            // Critical: Log to STDERR so the problem is visible
            error_log(
                "AsyncFileHandler critical error: Could not open log file '{$this->logFile}'. " .
                "Check permissions and path."
            );
            return;
        }

        // Make resource non-blocking
        stream_set_blocking($resource, false);

        // Create ReactPHP writable stream
        $this->stream = new WritableResourceStream($resource, $this->loop);

        // Setup error handler
        $this->stream->on('error', function (Exception $e) {
            // Log to STDERR so we know there's a problem
            error_log("AsyncFileHandler stream error: " . $e->getMessage());
            $this->stream = null;
        });

        // Flush buffer if any
        $this->flushBuffer();
    }

    private function bufferWrite(string $data): void
    {
        // Prevent infinite buffer growth
        if (count($this->writeBuffer) >= self::MAX_BUFFER_SIZE) {
            // Drop oldest log to prevent memory overflow
            array_shift($this->writeBuffer);
        }

        $this->writeBuffer[] = $data;
    }

    private function flushBuffer(): void
    {
        if (empty($this->writeBuffer) || $this->stream === null) {
            return;
        }

        foreach ($this->writeBuffer as $data) {
            $this->stream->write($data);
        }

        $this->writeBuffer = [];
    }

    private function checkRotation(): void
    {
        $today = (int) date('d');

        if ($today === $this->currentDay) {
            return; // No rotation needed
        }

        // Close current stream
        if ($this->stream !== null) {
            $this->stream->close();
            $this->stream = null;
        }

        // Rotate files (note: blocking I/O, but only once per day)
        $this->rotateFiles();

        // Update day and reopen
        $this->currentDay = $today;
        $this->openStream();
    }

    private function rotateFiles(): void
    {
        // Get base filename (without extension)
        $pathInfo = pathinfo($this->logFile);
        $baseFile = $pathInfo['dirname'] . '/' . $pathInfo['filename'];
        $ext = $pathInfo['extension'] ?? 'log';

        // Delete oldest file if exists
        $oldestFile = $baseFile . '-' . date('Y-m-d', strtotime("-{$this->maxFiles} days")) . '.' . $ext;
        if (file_exists($oldestFile)) {
            @unlink($oldestFile);
        }

        // Rename current file to dated file
        if (file_exists($this->logFile)) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $datedFile = $baseFile . '-' . $yesterday . '.' . $ext;
            @rename($this->logFile, $datedFile);
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            $this->flushBuffer();
            $this->stream->close();
            $this->stream = null;
        }
    }
}
