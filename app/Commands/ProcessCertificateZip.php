<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\CertificateZipJobModel;
use App\Models\GeneratedCertificateModel;
use App\Libraries\S3Service;

class ProcessCertificateZip extends BaseCommand
{
    protected $group = 'Certificates';
    protected $name = 'certificates:zip';
    protected $description = 'Process pending certificate ZIP jobs';

    public function run(array $params)
    {
        $jobModel = new CertificateZipJobModel();
        $generatedModel = new GeneratedCertificateModel();
        $s3 = new S3Service();

        // 🔹 Fetch ONE pending job
        $job = $jobModel->where('status', 'pending')
            ->orderBy('id', 'ASC')
            ->first();

        if (!$job) {
            CLI::write('No pending ZIP jobs', 'yellow');
            return;
        }

        $jobId = $job['id'];
        CLI::write("Processing ZIP Job #{$jobId}", 'green');

        $jobModel->markProcessing($jobId);

        try {
            /* ----------------------------------------
             * Fetch generated certificates
             * -------------------------------------- */
            $files = $generatedModel->where('certificate_id', $job['certificate_id'])
                ->findAll();

            if (count($files) < 2) {
                throw new \RuntimeException('Not enough files to zip');
            }

            /* ----------------------------------------
             * Temp ZIP
             * -------------------------------------- */
            $tmpDir = WRITEPATH . 'tmp/';
            $zipPath = $tmpDir . "certificates_job_{$jobId}.zip";

            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            $zip = new \ZipArchive();
            $zip->open($zipPath, \ZipArchive::CREATE);

            /* ----------------------------------------
             * Download → ZIP → cleanup (one by one)
             * -------------------------------------- */
            foreach ($files as $i => $file) {

                // 🔥 Check cancellation before each file
                if ($jobModel->isCancelled($jobId)) {
                    CLI::write("ZIP Job {$jobId} cancelled", 'yellow');
                    unlink($zipPath ?? '');
                    return;
                }

                $tmpPdf = $tmpDir . basename($file['file_path']);

                $s3->downloadToLocal($file['file_path'], $tmpPdf);
                $zip->addFile($tmpPdf, basename($tmpPdf));
                unlink($tmpPdf);

                $jobModel->updateProgress($jobId, $i + 1);
            }

            $zip->close();

            /* ----------------------------------------
             * Upload ZIP to S3
             * -------------------------------------- */
            $zipKey = "certificates/{$job['certificate_id']}/zip/certificates.zip";

            $s3->uploadLocalFile($zipPath, $zipKey, [
                'mimeType' => 'application/zip',
            ]);

            unlink($zipPath);

            $jobModel->markCompleted($jobId, $zipKey);

            CLI::write("ZIP Job #{$jobId} completed", 'green');

        } catch (\Throwable $e) {
            $jobModel->markFailed($jobId, $e->getMessage());
            CLI::error("ZIP Job #{$jobId} failed: " . $e->getMessage());
        }
    }
}
