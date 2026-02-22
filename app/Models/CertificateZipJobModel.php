<?php
namespace App\Models;

use CodeIgniter\Model;

class CertificateZipJobModel extends Model
{
    protected $table = 'certificate_zip_jobs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'certificate_id',
        'status',
        'total_files',
        'processed_files',
        'zip_s3_key',
        'error_message',
        'created_at',
        'updated_at',
        'batch_prefix',
    ];

    /* ---------------- Create Job ---------------- */

    public function createJob(int $certificateId, int $totalFiles, string $batchPrefix = ''): int
    {
        $this->insert([
            'certificate_id' => $certificateId,
            'status' => 'pending',
            'total_files' => $totalFiles,
            'processed_files' => 0,
            'batch_prefix' => $batchPrefix,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->getInsertID();
    }

    /* ---------------- Status Updates ---------------- */

    public function markProcessing(int $jobId): void
    {
        $this->update($jobId, [
            'status' => 'processing',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateProgress(int $jobId, int $processed): void
    {
        $this->update($jobId, [
            'processed_files' => $processed,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markCompleted(int $jobId, string $zipKey): void
    {
        $this->update($jobId, [
            'status' => 'completed',
            'zip_s3_key' => $zipKey,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markFailed(int $jobId, string $error): void
    {
        $this->update($jobId, [
            'status' => 'failed',
            'error_message' => $error,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ---------------- Fetch ---------------- */

    public function findJob(int $jobId): ?array
    {
        return $this->find($jobId);
    }

    /**
     * Check if there is already an active ZIP job
     */
    public function hasActiveJob(int $certificateId): bool
    {
        return $this->where('certificate_id', $certificateId)
            ->whereIn('status', ['pending', 'processing'])
            ->countAllResults() > 0;
    }

    public function findActiveJobByCertificate(int $certificateId): ?array
    {
        return $this->where('certificate_id', $certificateId)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id', 'DESC')
            ->first();
    }
    public function cancelJob(int $jobId): bool
    {
        $job = $this->find($jobId);

        if (!$job || !in_array($job['status'], ['pending', 'processing'])) {
            return false;
        }

        return (bool) $this->update($jobId, [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function isCancelled(int $jobId): bool
    {
        $job = $this->find($jobId);
        return $job && $job['status'] === 'cancelled';
    }


}
