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

    /**
     * Replaces a certificate's whole layout.
     *
     * upsertVar() below is keyed on the field name, which quietly allows a
     * field to be placed exactly once. That is fine for a certificate, where a
     * name appears in one spot, and wrong for an ID card: its QR square, its
     * barcode and the printed reference are all the same field drawn three
     * ways, and keying on the name collapsed them into whichever was saved
     * last. A layout is sent whole, so it is stored whole.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return int how many placements were stored
     */
    public function replaceFor(int $certificateId, array $rows): int
    {
        $this->where('certificate_id', $certificateId)->delete();

        if ($rows === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');

        $this->insertBatch(array_map(
            static fn (array $row) => $row + [
                'certificate_id' => $certificateId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            $rows
        ));

        return count($rows);
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
