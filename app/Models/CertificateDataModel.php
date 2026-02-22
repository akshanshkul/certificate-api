<?php
namespace App\Models;

use CodeIgniter\Model;

class CertificateDataModel extends Model
{
    protected $table = 'certificate_data';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'certificate_id',
        'name',
        'email',
        'mobile',
        'data',
        'created_at',
        'file_path',
        'download_url'
    ];

    public function insertRow(int $certificateId, array $row)
    {
        $this->insert([
            'certificate_id' => $certificateId,
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'mobile' => $row['mobile'] ?? null,
            'data' => json_encode($row),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->getInsertID();
    }

    public function listByCertificate(int $certificateId)
    {
        return $this->where('certificate_id', $certificateId)->orderBy('id', 'ASC')->findAll();
    }
}
