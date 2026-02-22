<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\CertificateZipJobModel;
use App\Models\GeneratedCertificateModel;
use App\Libraries\S3Service;

class CronController extends BaseController
{
    use ResponseTrait;

    /**
     * POST /api/cron/certificates/zip
     */
    public function processCertificateZip()
    {
        /* ----------------------------------------
         * Security
         * -------------------------------------- */
        $token = $this->request->getHeaderLine('X-CRON-TOKEN');
        if ($token !== getenv('CRON_SECRET')) {
            return $this->failUnauthorized('Invalid cron token');
        }

        $jobModel = new CertificateZipJobModel();
        $generatedModel = new GeneratedCertificateModel();
        $s3 = new S3Service();

        /* ----------------------------------------
         * Fetch ONE pending job
         * -------------------------------------- */
        $job = $jobModel
            ->where('status', 'pending')
            ->orderBy('id', 'ASC')
            ->first();

        if (!$job) {
            return $this->respond([
                'success' => 1,
                'message' => 'No pending ZIP jobs'
            ]);
        }

        $jobId = (int) $job['id'];
        $jobModel->markProcessing($jobId);

        $zip = null;
        $zipPath = null;
        $tmpFiles = []; // Track all temporary files for cleanup

        try {
            /* ----------------------------------------
             * Fetch generated certificates for the SPECIFIC batch
             * -------------------------------------- */
            $query = $generatedModel->where('certificate_id', $job['certificate_id']);

            if (!empty($job['batch_prefix'])) {
                // Filter by path prefix if batch_prefix is set
                $query->like('file_path', $job['batch_prefix'], 'after');
            }

            $files = $query->findAll();

            if (empty($files)) {
                throw new \RuntimeException('No files found for this batch/certificate');
            }

            /* ----------------------------------------
             * Prepare tmp directory (CRITICAL FIX)
             * -------------------------------------- */
            $tmpDir = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;

            if (!is_dir($tmpDir)) {
                if (!mkdir($tmpDir, 0775, true)) {
                    throw new \RuntimeException('Failed to create tmp directory: ' . $tmpDir);
                }
            }

            if (!is_writable($tmpDir)) {
                throw new \RuntimeException('Tmp directory is not writable: ' . $tmpDir);
            }

            /* ----------------------------------------
             * Create ZIP (ONLY ONCE)
             * -------------------------------------- */
            $zipPath = $tmpDir . "certificates_job_{$jobId}.zip";

            $zip = new \ZipArchive();
            $openResult = $zip->open(
                $zipPath,
                \ZipArchive::CREATE | \ZipArchive::OVERWRITE
            );

            if ($openResult !== true) {
                throw new \RuntimeException("ZIP open failed ({$openResult})");
            }

            /* ----------------------------------------
             * Add PDFs to ZIP
             * -------------------------------------- */
            foreach ($files as $i => $file) {

                if ($jobModel->isCancelled($jobId)) {
                    throw new \RuntimeException('ZIP job cancelled');
                }

                $tmpPdf = $tmpDir . uniqid('pdf_', true) . '.pdf';
                $tmpFiles[] = $tmpPdf; // Track for cleanup

                $s3->downloadToLocal($file['file_path'], $tmpPdf);

                if (!file_exists($tmpPdf)) {
                    throw new \RuntimeException(
                        'Downloaded file missing: ' . $file['file_path']
                    );
                }

                $zip->addFile($tmpPdf, basename($file['file_path']));

                $jobModel->updateProgress($jobId, $i + 1);
            }

            /* ----------------------------------------
             * CLOSE ZIP — ONLY PLACE
             * -------------------------------------- */
            $zip->close();

            // Clean up temporary PDF files after ZIP is closed
            foreach ($tmpFiles as $tmpPdf) {
                if (file_exists($tmpPdf)) {
                    unlink($tmpPdf);
                }
            }
            $tmpFiles = []; // Clear the array

            if (!file_exists($zipPath)) {
                throw new \RuntimeException('ZIP file was not created');
            }

            /* ----------------------------------------
             * Upload ZIP to S3
             * -------------------------------------- */
            $zipKey = "certificates/data/zip/certificates_job_{$jobId}.zip";

            $s3->uploadLocalFile($zipPath, $zipKey, [
                'mimeType' => 'application/zip'
            ]);

            unlink($zipPath);
            $zipPath = null; // Mark as cleaned up

            $jobModel->markCompleted($jobId, $zipKey);

            return $this->respond([
                'success' => 1,
                'job_id' => $jobId,
                'status' => 'completed'
            ], 200);

        } catch (\Throwable $e) {
            // Cleanup all temporary files
            foreach ($tmpFiles as $tmpPdf) {
                if (file_exists($tmpPdf)) {
                    @unlink($tmpPdf);
                }
            }

            // Close ZIP if still open (important!)
            if ($zip instanceof \ZipArchive) {
                $zip->close();
            }

            // Cleanup ZIP file if it exists
            if ($zipPath && file_exists($zipPath)) {
                @unlink($zipPath);
            }

            $jobModel->markFailed($jobId, $e->getMessage());

            return $this->failServerError($e->getMessage());
        }
    }
}