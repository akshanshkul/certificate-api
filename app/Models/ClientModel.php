<?php
namespace App\Models;

use CodeIgniter\Model;

class ClientModel extends Model
{
    protected $table            = 'clients';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;

    protected $allowedFields = [
        'name',
        'contact_email',
        'contact_phone',
        'website_url',
        'callback_url',
        'plan',
        'status',
        'allowed_ips',
        'notes',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Get single client by ID
     */
    public function findById($id)
    {
        return $this->where('id', $id)->first();
    }

    /**
     * Deactivate client
     */
    public function deactivateClient($id)
    {
        return $this->update($id, [
            'status' => 'disabled'
        ]);
    }

    /**
     * Activate client
     */
    public function activateClient($id)
    {
        return $this->update($id, [
            'status' => 'active'
        ]);
    }
}
