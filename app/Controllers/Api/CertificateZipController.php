<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\CertificateZipJobModel;
use App\Models\GeneratedCertificateModel;
use CodeIgniter\API\ResponseTrait;
use function PHPUnit\Framework\isEmpty;

class CertificateZipController extends BaseController
{
    use ResponseTrait;
    protected CertificateZipJobModel $jobModel;
    protected GeneratedCertificateModel $generatedModel;

    public function __construct()
    {
        $this->jobModel = new CertificateZipJobModel();
        $this->generatedModel = new GeneratedCertificateModel();
    }

    public function create()
    {
        $data = $this->request->getJSON(true);
        $certificateId = (int) ($data['certificate_id'] ?? 0);

        if (!$certificateId) {
            return $this->respond([
                'success' => 0,
                'message' => 'certificate_id required'
            ], 422);
        }

        // 🔒 PREVENT duplicate jobs
        if ($this->jobModel->hasActiveJob($certificateId)) {
            return $this->respond([
                'success' => 0,
                'message' => 'A ZIP job is already in progress for this certificate'
            ], 409); // Conflict
        }

        $files = $this->generatedModel
            ->where('certificate_id', $certificateId)
            ->findAll();

        if (count($files) < 2) {
            return $this->respond([
                'success' => 0,
                'message' => 'At least 2 certificates required for ZIP'
            ], 400);
        }

        $jobId = $this->jobModel->createJob($certificateId, count($files));

        return $this->respond([
            'success' => 1,
            'job_id' => $jobId,
            'status' => 'pending',
        ], 201);
    }

    /**
     * GET or POST /api/certificate/zip/status
     */
    public function status()
    {
        // Accept JSON or form-data
        $payload = $this->request->getJSON(true) ?? [];

        $jobId = (int) ($payload['job_id']
            ?? $this->request->getPost('job_id')
            ?? 0);

        if ($jobId > 0) {
            $job = $this->jobModel->findJob($jobId);
        } else {
            $certificateId = (int) (
                $payload['certificate_id']
                ?? $this->request->getGet('certificate_id')
                ?? 0
            );

            if ($certificateId <= 0) {
                return $this->respond([
                    'success' => 0,
                    'message' => 'job_id or certificate_id required'
                ], 422);
            }

            $job = $this->jobModel->findActiveJobByCertificate($certificateId);
        }

        if (!$job) {
            return $this->respond([
                'success' => 0,
                'message' => 'No active ZIP job found'
            ], 404);
        }

        $progress = $job['total_files'] > 0
            ? round(($job['processed_files'] / $job['total_files']) * 100, 2)
            : 0;

        return $this->respond([
            'success' => 1,
            'job_id' => (int) $job['id'],
            'status' => $job['status'],
            'progress' => $progress,
            'zip_key' => $job['status'] === 'completed'
                ? $job['zip_s3_key']
                : null,
        ]);
    }
    /**
     * POST /api/certificate/zip/cancel
     */
    public function cancel()
    {
        $payload = $this->request->getJSON(true) ?? [];
        $jobId = (int) ($payload['job_id'] ?? 0);

        if (!$jobId) {
            return $this->respond([
                'success' => 0,
                'message' => 'job_id required'
            ], 422);
        }

        $ok = $this->jobModel->cancelJob($jobId);

        if (!$ok) {
            return $this->respond([
                'success' => 0,
                'message' => 'Job not found or cannot be cancelled'
            ], 400);
        }

        return $this->respond([
            'success' => 1,
            'message' => 'ZIP job cancelled'
        ]);
    }


}
