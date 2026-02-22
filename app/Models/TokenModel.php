<?php
namespace App\Models;

use CodeIgniter\Model;
use DateTime;
class TokenModel extends Model
{
    protected $table = 'tokens';
    protected $primaryKey = 'id';
    protected $allowedFields = ['user_id', 'token', 'expires_at', 'updated_at', 'created_at'];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';

    public function getValidToken(string $token)
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('token', $token)
            ->where('expires_at >=', $now)
            ->first();
    }
    public function extendToken(string $tokenHash)
    {
        $date = new DateTime();
        $date->modify('+10 minutes');
        $expires_at = $date->format('Y-m-d H:i:s');

        return $this->where('token', $tokenHash)
            ->update(null, ['expires_at' => $expires_at]);
    }


    public function revokeToken(string $token)
    {
        return $this->where('token', $token)->delete();
    }

    public function deleteExpired()
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('expires_at <', $now)->delete();
    }
}
