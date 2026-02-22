<?php

namespace App\Libraries;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Config\S3 as S3Config;

class S3Service
{
    protected $s3Client;
    protected $config;
    protected $bucket;

    public function __construct()
    {
        $this->config = config('S3');

        if (!$this->config->enabled) {
            log_message('info', 'S3 storage is disabled');
            return;
        }

        if (!$this->config->isConfigured()) {
            log_message('warning', 'S3 is not properly configured');
            $this->config->enabled = false;
            return;
        }

        $this->bucket = $this->config->bucket;
        $this->initializeS3Client();
    }

    private function initializeS3Client()
    {
        try {
            $clientConfig = [
                'version' => 'latest',
                'region' => $this->config->region,
                'credentials' => [
                    'key' => $this->config->accessKey,
                    'secret' => $this->config->secretKey,
                ],
            ];

            // Add endpoint for S3-compatible services
            if (!empty($this->config->endpoint)) {
                $clientConfig['endpoint'] = $this->config->endpoint;
                $clientConfig['use_path_style_endpoint'] = $this->config->usePathStyleEndpoint;
            }

            $this->s3Client = new S3Client($clientConfig);

            // Test connection
            $this->s3Client->listBuckets();
            log_message('info', 'S3 Client initialized successfully');

        } catch (AwsException $e) {
            log_message('error', 'S3 Client initialization failed: ' . $e->getMessage());
            $this->s3Client = null;
        } catch (\Exception $e) {
            log_message('error', 'S3 Client initialization error: ' . $e->getMessage());
            $this->s3Client = null;
        }
    }

    /**
     * Basic upload function - uploads any document/file to specified S3 location
     * 
     * @param mixed $file Can be: 
     *                    - File path string (local file)
     *                    - UploadedFile object (CodeIgniter)
     *                    - File content string
     * @param string $destination S3 destination path (e.g., 'certificates/templates/myfile.jpg')
     * @param array $options Optional settings:
     *                      - 'isPublic' (bool): Make file publicly accessible (default: true)
     *                      - 'mimeType' (string): Override mime type
     *                      - 'metadata' (array): Additional metadata
     *                      - 'cacheControl' (string): Cache control header
     *                      - 'contentDisposition' (string): Content disposition header
     * @return array|null S3 upload result details on success, null on failure
     */
    public function uploadDocument($file, $destination, $options = [])
    {
        if (!$this->s3Client) {
            log_message('error', 'S3 Client not initialized');
            return null;
        }

        // Default options
        $defaultOptions = [
            'filename' => null,
            'isPublic' => true,
            'mimeType' => null,
            'metadata' => [],
            'cacheControl' => 'public, max-age=31536000',
            'contentDisposition' => null,
        ];

        // Handle legacy/alternate option keys
        if (isset($options['ContentType']) && !isset($options['mimeType'])) {
            $options['mimeType'] = $options['ContentType'];
        }

        $options = array_merge($defaultOptions, $options);

        try {
            // **FIX 1: Generate the full S3 key with filename**
            $s3Key = $this->generateS3Key($destination, $options);

            // Prepare upload parameters
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $s3Key, // Use the generated key
            ];

            // Handle different file input types
            if (is_string($file) && file_exists($file)) {
                // File path
                $params['SourceFile'] = $file;

                // Auto-detect mime type if not provided
                if (!$options['mimeType']) {
                    $options['mimeType'] = mime_content_type($file) ?: 'application/octet-stream';
                }

            } elseif (is_object($file) && method_exists($file, 'getTempName')) {
                // CodeIgniter UploadedFile object
                $tempFile = $file->getTempName();
                if (!file_exists($tempFile)) {
                    log_message('error', 'Uploaded file temp not found');
                    return null;
                }
                $params['SourceFile'] = $tempFile;

                if (!$options['mimeType']) {
                    $options['mimeType'] = $file->getMimeType() ?: 'application/octet-stream';
                }

            } elseif (is_string($file) && !file_exists($file)) {
                // String content (not a file path)
                $params['Body'] = $file;

                if (!$options['mimeType']) {
                    $options['mimeType'] = 'text/plain';
                }

            } else {
                log_message('error', 'Invalid file input type');
                return null;
            }

            // Set ACL if public
            if ($options['isPublic']) {
                $params['ACL'] = 'public-read';
            }

            // Set Content-Type
            if ($options['mimeType']) {
                $params['ContentType'] = $options['mimeType'];
            }

            // Set Cache-Control
            if ($options['cacheControl']) {
                $params['CacheControl'] = $options['cacheControl'];
            }

            // Set Content-Disposition
            if ($options['contentDisposition']) {
                $params['ContentDisposition'] = $options['contentDisposition'];
            }

            // Add metadata if provided
            if (!empty($options['metadata'])) {
                $params['Metadata'] = $options['metadata'];
            }

            // Upload to S3
            $result = $this->s3Client->putObject($params);

            // Log successful upload
            $fileSize = isset($params['SourceFile']) ? filesize($params['SourceFile']) . ' bytes' :
                (isset($params['Body']) ? strlen($params['Body']) . ' bytes' : 'unknown');

            log_message('info', "Document uploaded to S3: {$s3Key} ({$fileSize})");

            // **FIX 2: Return array with all details**
            return [
                'url' => $this->getPublicUrl($s3Key),
                'key' => $s3Key,
                'filename' => basename($s3Key),
                'bucket' => $this->bucket,
                'size' => $fileSize,
                'mime_type' => $options['mimeType'],
                'etag' => $result['ETag'] ?? null,
                'version_id' => $result['VersionId'] ?? null,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

        } catch (AwsException $e) {
            // Handle ACL error gracefully
            if ($e->getAwsErrorCode() === 'AccessControlListNotSupported' && $options['isPublic']) {
                try {
                    unset($params['ACL']);
                    $result = $this->s3Client->putObject($params);
                    log_message('info', 'Document uploaded without ACL: ' . $s3Key);

                    return [
                        'url' => $this->getPublicUrl($s3Key),
                        'key' => $s3Key,
                        'filename' => basename($s3Key),
                        'bucket' => $this->bucket,
                        'size' => $fileSize ?? 'unknown',
                        'mime_type' => $options['mimeType'],
                        'warning' => 'Uploaded without ACL (bucket ACLs disabled)',
                    ];
                } catch (AwsException $e2) {
                    log_message('error', 'S3 upload failed (without ACL): ' . $e2->getMessage());
                    return null;
                }
            }

            log_message('error', 'S3 upload failed: ' . $e->getMessage());
            return null;

        } catch (\Exception $e) {
            log_message('error', 'S3 upload error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate S3 key from destination and filename option
     */
    private function generateS3Key($destination, $options)
    {
        // Clean destination path - replace backslashes with forward slashes for S3
        $destination = str_replace('\\', '/', trim($destination, '/\\'));

        // If filename is provided, append it to destination
        if (!empty($options['filename'])) {
            $filename = ltrim(str_replace('\\', '/', $options['filename']), '/');
            return $destination . '/' . $filename;
        }

        // If no filename, use destination as is
        return $destination;
    }
    public function getPublicUrl($s3Key)
    {
        if (!$this->s3Client) {
            return null;
        }

        $url = '';
        if ($this->config->cdnUrl) {
            $url = rtrim($this->config->cdnUrl, '/') . '/' . ltrim($s3Key, '/');
        } else {
            // Generate S3 URL
            $url = $this->s3Client->getObjectUrl($this->bucket, $s3Key);
        }

        // Ensure it's a full URL
        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            // Fallback to manual construction if getObjectUrl fails to give full URL in some environments
            $region = $this->config->region;
            $bucket = $this->bucket;
            $url = "https://{$bucket}.s3.{$region}.amazonaws.com/" . ltrim($s3Key, '/');
        }

        return $url;
    }

    public function fileExists($s3Key)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);
            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFound') {
                return false;
            }
            log_message('error', 'S3 check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteFile($s3Key)
    {
        if (!$this->s3Client) {
            return false;
        }

        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);
            log_message('info', 'File deleted from S3: ' . $s3Key);
            return true;
        } catch (AwsException $e) {
            log_message('error', 'S3 delete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function isEnabled()
    {
        return $this->config->enabled && $this->s3Client !== null;
    }

    /**
     * Get signed URL for temporary access (for private files)
     */
    public function getSignedUrl($s3Key, $expires = '+1 hour')
    {
        if (!$this->s3Client) {
            return null;
        }

        try {
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            $request = $this->s3Client->createPresignedRequest($cmd, $expires);
            return (string) $request->getUri();

        } catch (\Exception $e) {
            log_message('error', 'Failed to generate signed URL: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Upload a LOCAL FILE (TCPDF / ZIP output) to S3
     * Wrapper around uploadDocument()
     *
     * @param string $localPath Absolute local filesystem path
     * @param string $s3Key     Full S3 key including filename
     * @param array  $options  Extra S3 options
     *
     * @return string Public or CDN URL
     */
    public function uploadLocalFile(string $localPath, string $s3Key, array $options = []): string
    {
        if (!file_exists($localPath)) {
            throw new \RuntimeException('Local file not found: ' . $localPath);
        }

        $destination = dirname($s3Key);
        $filename = basename($s3Key);

        $result = $this->uploadDocument(
            $localPath,
            $destination,
            array_merge([
                'filename' => $filename,
                'isPublic' => $this->config->publicRead, // Use config setting instead of hardcoded false
            ], $options)
        );

        if (!$result || empty($result['url'])) {
            throw new \RuntimeException('Failed to upload file to S3');
        }

        return $result['url'];
    }
    /**
     * Generate a presigned download URL
     *
     * @param string $s3Key
     * @param int    $expires Seconds (default 1 hour)
     *
     * @return string
     */
    public function presignedUrl(string $s3Key, int $expires = 3600): string
    {
        $url = $this->getSignedUrl($s3Key, '+' . $expires . ' seconds');

        if (!$url) {
            throw new \RuntimeException('Failed to generate presigned URL');
        }

        return $url;
    }

    /**
     * Download S3 object to local file
     *
     * @param string $s3Key  Full S3 key (from DB)
     * @param string $localPath Absolute local path
     * @return bool
     */
    public function downloadToLocal(string $s3Path, string $localPath): bool
    {
        if (!$this->s3Client) {
            throw new \RuntimeException('S3 client not initialized');
        }

        try {
            if (str_starts_with($s3Path, 'http')) {
                $parsed = parse_url($s3Path);
                $s3Key = ltrim($parsed['path'], '/');
            } else {
                $s3Key = ltrim($s3Path, '/');
            }

            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'SaveAs' => $localPath,
            ]);

            return file_exists($localPath);

        } catch (\Throwable $e) {
            log_message('error', 'S3 download failed: ' . $e->getMessage());
            throw $e;
        }
    }



    /**
     * List sub-folders (prefixes) under a parent prefix
     */
    public function listPrefixes($parentPrefix)
    {
        if (!$this->s3Client)
            return [];

        $parentPrefix = rtrim($parentPrefix, '/') . '/';

        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $parentPrefix,
                'Delimiter' => '/'
            ]);

            $prefixes = [];
            if (isset($result['CommonPrefixes'])) {
                foreach ($result['CommonPrefixes'] as $prefix) {
                    $prefixes[] = $prefix['Prefix'];
                }
            }

            return $prefixes;
        } catch (AwsException $e) {
            log_message('error', 'S3 list prefixes failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete all objects with a specific prefix (acts like deleting a folder)
     */
    public function deletePrefix($prefix)
    {
        if (!$this->s3Client)
            return false;

        try {
            $this->s3Client->deleteMatchingObjects($this->bucket, $prefix);
            log_message('info', 'Prefix deleted from S3: ' . $prefix);
            return true;
        } catch (AwsException $e) {
            log_message('error', 'S3 delete prefix failed: ' . $e->getMessage());
            return false;
        }
    }
}
