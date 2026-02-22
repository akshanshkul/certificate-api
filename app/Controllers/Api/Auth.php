<?php
namespace App\Controllers\Api;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use App\Models\UserModel;
use App\Models\TokenModel;

class Auth extends Controller
{
    use ResponseTrait;
    protected $userModel;
    protected $tokenModel;
    protected $tokenTTL = 600; // 10 minutes in seconds

    public function __construct()
    {
        date_default_timezone_set('Asia/Kolkata');
        $this->userModel = new UserModel();
        $this->tokenModel = new TokenModel();

        helper('jwt'); // make sure JWT helper is loaded
    }

    /**
     * Register a new client
     */
    public function registerUser()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'name, email, password required'
            ], 422);
        }

        if ($this->userModel->findByEmail($data['email'])) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Email already exists'
            ], 409);
        }

        $insertId = $this->userModel->insert([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'user_type' => 'client',
        ]);

        if (!$insertId) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Failed to register'
            ], 500);
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'User registered successfully'
        ], 201);
    }

    /**
     * Login user and generate JWT
     */
    public function login()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        if (empty($data['email']) || empty($data['password'])) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'email and password required'
            ], 422);
        }

        $user = $this->userModel->findByEmail($data['email']);
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Build token payload
        $issuedAt = time();
        $expireAt = $issuedAt + $this->tokenTTL;

        // Generate a secure random opaque token (plain)
        $plainToken = bin2hex(random_bytes(32));
        // Hash token before storing in DB
        $hash = hash('sha256', $plainToken);
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expireAt,
            'sub' => $user['id'],
            'token' => $hash,
            'type' => $user['user_type']
        ];


        // Generate JWT using helper
        $jwt = jwt_encode($payload);

        // Store token inside DB
        $this->tokenModel->insert([
            'user_id' => $user['id'],
            'token' => $hash,
            'expires_at' => date('Y-m-d H:i:s', $expireAt),
        ]);

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Login successful',
            'access_token' => $jwt,
        ]);
    }

    /**
     * Logout user (revoke token)
     */
    public function logout()
    {
        $auth = $this->request->getHeaderLine('Authorization');

        if (!$auth || !preg_match('/Bearer\s(\S+)/', $auth, $m)) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Token not provided'
            ], 401);
        }

        $token = $m[1];
        $this->tokenModel->revokeToken($token);

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Logged out'
        ]);
    }

    /**
     * Create a client (only clients table)
     * POST /api/clients/create
     * Payload (JSON):
     * {
     *   "name": "Acme Corp",
     *   "contact_email": "dev@acme.com",    // optional
     *   "contact_phone": "9988776655",      // optional
     *   "website_url": "https://acme.com",  // optional
     *   "callback_url": "https://acme.com/webhook", // optional
     *   "plan": "pro",                      // optional: free|basic|pro|enterprise
     *   "allowed_ips": "1.2.3.4,2.2.2.0/24",// optional
     *   "notes": "some notes"               // optional
     * }
     */
    public function createClient()
    {
        // get input
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        // Basic validation
        if (empty($data['name'])) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'name is required'
            ], 422);
        }

        if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'invalid contact_email'
            ], 422);
        }

        // normalize plan
        $allowedPlans = ['free', 'basic', 'pro', 'enterprise'];
        $plan = in_array($data['plan'] ?? 'free', $allowedPlans) ? $data['plan'] : 'free';

        // prepare insert row
        $clientRow = [
            'name' => $data['name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'callback_url' => $data['callback_url'] ?? null,
            'plan' => $plan,
            'status' => 'active',
            'allowed_ips' => $data['allowed_ips'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            // Use the model if available
            if (isset($this->clientModel) && $this->clientModel instanceof \App\Models\ClientModel) {
                $insertId = $this->clientModel->insert($clientRow);
            } else {
                // fallback to DB builder
                $db = \Config\Database::connect();
                $db->table('clients')->insert($clientRow);
                $insertId = (int) $db->insertID();
            }
        } catch (\Exception $e) {
            // log($e->getMessage()); // optionally log
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Failed to create client'
            ], 500);
        }

        if (!$insertId) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Failed to create client'
            ], 500);
        }

        // fetch created client (exclude sensitive data)
        if (isset($this->clientModel) && $this->clientModel instanceof \App\Models\ClientModel) {
            $client = $this->clientModel->find($insertId);
        } else {
            $db = \Config\Database::connect();
            $client = $db->table('clients')->where('id', $insertId)->get()->getRowArray();
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Client created',
            'client' => [
                'id' => (int) $client['id'],
                'name' => $client['name'],
                'contact_email' => $client['contact_email'] ?? null,
                'website_url' => $client['website_url'] ?? null,
                'plan' => $client['plan'] ?? null,
                'status' => $client['status'] ?? null,
                'created_at' => $client['created_at'] ?? null
            ]
        ], 201);
    }
}
