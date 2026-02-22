<?php
namespace App\Models;

use CodeIgniter\Model;

class CertificateTemplateVarModel extends Model
{
    protected $table = 'certificate_template_vars';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'certificate_id','var_key','label','x','y','font_size','font_family',
        'color','align','order_index','options','created_at','updated_at'
    ];

    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function listByCertificate(int $certificateId)
    {
        return $this->where('certificate_id',$certificateId)->orderBy('order_index','ASC')->findAll();
    }

    public function upsertVar(int $certificateId, string $key, array $props)
    {
        $existing = $this->where('certificate_id',$certificateId)->where('var_key',$key)->first();
        $data = array_merge(['certificate_id'=>$certificateId,'var_key'=>$key], $props);
        if ($existing) {
            return $this->update($existing['id'], $data);
        }
        return $this->insert($data);
    }
}
