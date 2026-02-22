<?php
namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use App\Models\TokenModel;
use App\Controllers\Api\Auth as AuthController;
use Config\Services;

class JWTAuth implements FilterInterface
{
    protected $tokenModel;
    protected $authController;

    /**
     * Constructor
     *
     * Instantiates a new instance of the TokenModel and
     * AuthController classes.
     */
    public function __construct()
    {
        $this->tokenModel = new TokenModel();
        // we want access to jwt helpers — instantiate controller (small hack) or move helpers to a helper file
        $this->authController = new AuthController();
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'error' => 'Invalid Access! Login Again']);
        }

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'error' => 'Invalid Access!']);
        }

        $token = $matches[1];

        // decode & verify signature + exp
        $payload = jwt_decode($token);
        if (!$payload) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'error' => 'Invalid or expired token']);
        }

        // check token exists and not expired in DB
        $dbToken = $this->tokenModel->getValidToken($payload['token']);
        if (!$dbToken) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['success' => 0, 'error' => 'Token revoked or expired']);
        } else {
            $this->tokenModel->extendToken($payload['token']);
        }

        // attach user info to request so controllers can access it
        // Note: In CI4 you can use request->setUserData (no built-in) — so attach to current request via attribute:
        $request->user = [
            'id' => $payload['sub'] ?? null,
            'user_type' => $payload['type'] ?? null,
        ];

        // allow request to continue
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
