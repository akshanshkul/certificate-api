<?php
namespace App\Models;

use CodeIgniter\Model;

class CertificateModel extends Model
{
    protected $table = 'certificates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'client_id',
        'name',
        'description',
        'orientation',
        'page_width_mm',
        'page_height_mm',
        'template_path',
        'data_keys',
        'template_updated_at',
        'has_generated',
        'last_generated_url',
        'status',
        'notes',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    public function listByClient(int $clientId)
    {
        return $this->where('client_id', $clientId)->orderBy('id', 'DESC')->findAll();
    }

    public function findForClient(int $id, int $clientId)
    {
        return $this->where('id', $id)->where('client_id', $clientId)->first();
    }

    public function setTemplatePath(int $id, string $path)
    {
        return $this->update($id, ['template_path' => $path, 'template_updated_at' => date('Y-m-d H:i:s')]);
    }

    public function updateDataKeys(int $id, array $keys)
    {
        return $this->update($id, [
            'data_keys' => json_encode(array_values($keys)),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function markGenerated(int $id, string $url = null)
    {
        return $this->update($id, ['has_generated' => 1, 'last_generated_url' => $url]);
    }
}
