<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class S3 extends BaseConfig
{
    public $enabled;
    public $region;
    public $version = 'latest';
    public $bucket;
    public $accessKey;
    public $secretKey;
    public $endpoint;
    public $usePathStyleEndpoint;
    public $cdnUrl;
    
    // Folder structure in S3
    public $certificatesPath = 'certificates/';
    public $templatesPath = 'templates/';
    public $generatedPath = 'generated/';
    
    // Whether to make files public (read-only)
    public $publicRead = true;
    
    // Cache settings
    public $cacheDuration = 3600; // 1 hour
    
    // Whether to keep local copies after uploading to S3
    public $keepLocalCopies = false;

    public function __construct()
    {
        parent::__construct();
        
        // Load values from .env file
        $this->enabled = env('S3_ENABLED', false);
        $this->region = env('S3_REGION', 'us-east-1');
        $this->bucket = env('S3_BUCKET', '');
        $this->accessKey = env('S3_ACCESS_KEY', '');
        $this->secretKey = env('S3_SECRET_KEY', '');
        $this->endpoint = env('S3_ENDPOINT', null);
        $this->usePathStyleEndpoint = env('S3_USE_PATH_STYLE', false);
        $this->cdnUrl = env('S3_CDN_URL', null);
        
        // Optional: Override paths from .env
        $this->certificatesPath = env('S3_CERTIFICATES_PATH', 'certificates/');
        $this->templatesPath = env('S3_TEMPLATES_PATH', 'templates/');
        $this->generatedPath = env('S3_GENERATED_PATH', 'generated/');
        
        // Optional: Override other settings
        $this->publicRead = env('S3_PUBLIC_READ', true);
        $this->keepLocalCopies = env('S3_KEEP_LOCAL_COPIES', false);
        $this->cacheDuration = env('S3_CACHE_DURATION', 3600);
    }
    
    /**
     * Get the full S3 endpoint URL
     */
    public function getEndpoint()
    {
        if ($this->endpoint) {
            return rtrim($this->endpoint, '/');
        }
        
        // Default AWS S3 endpoint
        if ($this->region === 'us-east-1') {
            return 'https://s3.amazonaws.com';
        }
        
        return "https://s3.{$this->region}.amazonaws.com";
    }
    
    /**
     * Get public URL for a file
     */
    public function getPublicUrl($key)
    {
        // If CDN URL is set, use it
        if ($this->cdnUrl) {
            return rtrim($this->cdnUrl, '/') . '/' . ltrim($key, '/');
        }
        
        // Otherwise use S3 URL
        if ($this->usePathStyleEndpoint) {
            return $this->getEndpoint() . '/' . $this->bucket . '/' . ltrim($key, '/');
        } else {
            if ($this->endpoint) {
                // For S3-compatible services
                return $this->getEndpoint() . '/' . ltrim($key, '/');
            } else {
                // For AWS S3
                $domain = $this->region === 'us-east-1' 
                    ? 's3.amazonaws.com' 
                    : "s3.{$this->region}.amazonaws.com";
                return "https://{$this->bucket}.{$domain}/" . ltrim($key, '/');
            }
        }
    }
    
    /**
     * Check if S3 is properly configured
     */
    public function isConfigured()
    {
        return $this->enabled && 
               !empty($this->bucket) && 
               !empty($this->accessKey) && 
               !empty($this->secretKey);
    }
}