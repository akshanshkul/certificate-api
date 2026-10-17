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

    /**
     * Every row for a certificate, in one statement.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return int how many were written
     */
    public function insertRows(int $certificateId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->insertBatch(array_map(
            static fn (array $row) => [
                'certificate_id' => $certificateId,
                'name'           => $row['name'] ?? null,
                'email'          => $row['email'] ?? null,
                'mobile'         => $row['mobile'] ?? null,
                'data'           => json_encode($row),
                'created_at'     => $now,
            ],
            $rows
        ));
    }

    /**
     * Clears a certificate's rows, for a submission that is the whole list.
     *
     * @return int how many were removed
     */
    public function clearFor(int $certificateId): int
    {
        $existing = $this->where('certificate_id', $certificateId)->countAllResults();

        if ($existing > 0) {
            $this->where('certificate_id', $certificateId)->delete();
        }

        return $existing;
    }

    public function listByCertificate(int $certificateId)
    {
        return $this->where('certificate_id', $certificateId)->orderBy('id', 'ASC')->findAll();
    }
}
