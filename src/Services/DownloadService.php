<?php

namespace TurnkeyAgentic\Core\Services;

use CodeIgniter\HTTP\ResponseInterface;

class DownloadService
{
    protected StorageService $storage;

    public function __construct()
    {
        $this->storage = new StorageService();
    }

    public function streamPdf(string $relativePath, string $filename): ResponseInterface
    {
        if (!$this->storage->exists($relativePath)) {
            throw new \RuntimeException("File not found in storage: {$relativePath}");
        }

        $safeName = $this->sanitizeFilename($filename) . '.pdf';

        return response()
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->setHeader('Content-Length', (string) $this->storage->size($relativePath))
            ->setBody($this->storage->get($relativePath));
    }

    public function previewPdf(string $relativePath, string $filename = 'preview'): ResponseInterface
    {
        if (!$this->storage->exists($relativePath)) {
            throw new \RuntimeException("File not found in storage: {$relativePath}");
        }

        $safeName = $this->sanitizeFilename($filename) . '.pdf';

        return response()
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $safeName . '"')
            ->setBody($this->storage->get($relativePath));
    }

    public function streamText(string $content, string $filename): ResponseInterface
    {
        $safeName = $this->sanitizeFilename($filename) . '.txt';

        return response()
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->setBody($content);
    }

    /**
     * Stream a file directly from Google Drive without a temporary disk write.
     */
    public function streamFromDrive(string $driveFileId, string $filename): ResponseInterface
    {
        $drive    = new GoogleDriveService();
        $contents = $drive->downloadFile($driveFileId);
        $safeName = $this->sanitizeFilename($filename) . '.pdf';

        return response()
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setBody($contents);
    }

    protected function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/\.(pdf|txt|epub)$/i', '', $name);
        $name = preg_replace('/[^\w\s\-\.]/', '', $name);
        return trim($name) ?: 'download';
    }
}
