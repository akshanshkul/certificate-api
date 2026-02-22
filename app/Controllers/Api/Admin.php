<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\ClientModel;
use App\Models\CertificateModel;
use App\Models\GeneratedCertificateModel;
use App\Models\UserModel;

class Admin extends BaseController
{
    use ResponseTrait;

    protected $clientModel;
    protected $certificateModel;
    protected $generatedModel;
    protected $userModel;

    public function __construct()
    {
        $this->clientModel = new ClientModel();
        $this->certificateModel = new CertificateModel();
        $this->generatedModel = new GeneratedCertificateModel();
        $this->userModel = new UserModel();
    }

    /**
     * GET /api/admin/stats
     */
    public function getStats()
    {
        $stats = [
            'total_clients' => $this->clientModel->countAllResults(),
            'total_certificates' => $this->certificateModel->countAllResults(),
            'total_generated' => $this->generatedModel->countAllResults(),
            'recent_generations' => $this->generatedModel->orderBy('id', 'DESC')->limit(5)->findAll()
        ];

        return $this->respond([
            'success' => 1,
            'status' => true,
            'data' => $stats
        ]);
    }

    /**
     * GET /api/admin/clients
     */
    public function listClients()
    {
        $clients = $this->clientModel->findAll();
        return $this->respond([
            'success' => 1,
            'status' => true,
            'data' => $clients
        ]);
    }

    /**
     * POST /api/admin/clients
     */
    public function createClient()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        if (empty($data['name'])) {
            return $this->respond(['success' => 0, 'message' => 'Name is required'], 422);
        }

        $id = $this->clientModel->insert([
            'name' => $data['name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'success' => 1,
            'message' => 'Client created',
            'id' => $id
        ], 201);
    }

    /**
     * GET /api/admin/clients/(:num)
     */
    public function getClient($id)
    {
        $client = $this->clientModel->find($id);
        if (!$client) {
            return $this->respond(['success' => 0, 'message' => 'Client not found'], 404);
        }

        $certificates = $this->certificateModel->where('client_id', $id)->findAll();

        return $this->respond([
            'success' => 1,
            'status' => true,
            'data' => [
                'client' => $client,
                'certificates' => $certificates
            ]
        ]);
    }

    /**
     * PUT /api/admin/clients/(:num)
     */
    public function updateClient($id)
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        if (!$this->clientModel->find($id)) {
            return $this->respond(['success' => 0, 'message' => 'Client not found'], 404);
        }

        $this->clientModel->update($id, $data);
        return $this->respond(['success' => 1, 'message' => 'Client updated']);
    }

    /**
     * DELETE /api/admin/clients/(:num)
     */
    public function deleteClient($id)
    {
        if (!$this->clientModel->find($id)) {
            return $this->respond(['success' => 0, 'message' => 'Client not found'], 404);
        }

        $this->clientModel->delete($id);
        return $this->respond(['success' => 1, 'message' => 'Client deleted']);
    }

    /**
     * GET /api/admin/certificates
     */
    public function listCertificates()
    {
        $certificates = $this->certificateModel
            ->select('certificates.*, clients.name as client_name')
            ->join('clients', 'clients.id = certificates.client_id')
            ->findAll();

        return $this->respond([
            'success' => 1,
            'status' => true,
            'data' => $certificates
        ]);
    }

    /**
     * GET /api/admin/certificates/(:num)
     */
    public function getCertificateDetails($id)
    {
        $certificate = $this->certificateModel->find($id);
        if (!$certificate) {
            return $this->respond(['success' => 0, 'message' => 'Certificate not found'], 404);
        }

        $generations = $this->generatedModel->where('certificate_id', $id)->orderBy('id', 'DESC')->findAll();

        return $this->respond([
            'success' => 1,
            'status' => true,
            'data' => [
                'certificate' => $certificate,
                'generations' => $generations
            ]
        ]);
    }
}
