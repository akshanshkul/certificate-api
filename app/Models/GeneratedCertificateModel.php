<?php
namespace App\Models;

use CodeIgniter\Model;

class GeneratedCertificateModel extends Model
{
    protected $table = 'generated_certificates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'certificate_id',
        'certificate_data_id',
        'batch_id',       // ✅ NEW: Batch identification
        'file_path',      // S3 key
        'public_url',     // S3 / CDN / signed URL
        'data_snapshot',
        'created_at'
    ];

    /**
     * Create generated certificate record (S3-based)
     */
    public function createGenerated(
        int $certificateId,
        string $s3Key,
        array $data = [],
        ?int $dataId = null,
        ?string $publicUrl = null,
        ?string $batchId = null
    ): int {
        $this->insert([
            'certificate_id' => $certificateId,
            'certificate_data_id' => $dataId,
            'batch_id' => $batchId,
            'file_path' => $s3Key,                // ✅ S3 KEY
            'public_url' => $publicUrl,            // ✅ S3 / CDN URL
            'data_snapshot' => json_encode($data),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->getInsertID();
    }

    public function findLastByCertificate(int $certificateId)
    {
        return $this->where('certificate_id', $certificateId)
            ->orderBy('id', 'DESC')
            ->first();
    }
}
