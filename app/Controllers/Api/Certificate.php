<?php
namespace App\Controllers\Api;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use Config\Services;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use TCPDF;
use App\Libraries\S3Service;
use App\Libraries\AuthContext;
use App\Libraries\CertificatePDFService;
use App\Models\CertificateZipJobModel;

class Certificate extends Controller
{
    use ResponseTrait;
    protected $certificateModel;
    protected $templateVarModel;
    protected $dataModel;
    protected $generatedModel;
    protected $uploadPath;
    protected $s3Service;

    public function initController($request, $response, $logger)
    {

        parent::initController($request, $response, $logger);

        $this->certificateModel = new \App\Models\CertificateModel();
        $this->templateVarModel = new \App\Models\CertificateTemplateVarModel();
        $this->dataModel = new \App\Models\CertificateDataModel();
        $this->generatedModel = new \App\Models\GeneratedCertificateModel();
        $this->s3Service = new S3Service();
        // Base upload path
        // $this->uploadPath = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'certificates' . DIRECTORY_SEPARATOR;
        $this->uploadPath = 'uploads' . DIRECTORY_SEPARATOR . 'certificates' . DIRECTORY_SEPARATOR;
        // if (!is_dir($this->uploadPath)) {
        //     mkdir($this->uploadPath, 0755, true);
        // }
    }

    /**
     * POST /api/certificate/create
     * Payload: { name, description?, is_verifiable?, certificateId? }
     */
    public function create()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $name = trim($data['name'] ?? '');
        if (!$name) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'name required'], 422);
        }

        // client id from ApiKeyAuth filter (if present)
        $clientId = AuthContext::clientId();
        if ($clientId <= 0) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Invalid client context'], 403);
        }

        $isVerifiable = !empty($data['is_verifiable']) ? 1 : 0;
        $publicCode = trim((string) ($data['certificateId'] ?? ''));

        // If verification is enabled, public certificateId is required
        if ($isVerifiable === 1 && $publicCode === '') {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'certificateId is required when verification is enabled'
            ], 422);
        }



        // Build insert payload
        $insert = [
            'client_id' => $clientId,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'is_verifiable' => $isVerifiable,
            'certificate_code' => $publicCode !== '' ? $publicCode : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->certificateModel->insert($insert);
        $id = (int) $this->certificateModel->getInsertID();

        if (!$id) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Failed to create certificate'], 500);
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Certificate created',
            'certificate' => [
                'id' => $id,
                'name' => $name,
            ]
        ], 201);
    }


    /**
     * POST /api/certificate/template/upload
     * Form-data:
     *   certificate_id (required)
     *   template (file png/jpg) (required)
     *   logos[] (optional files)
     */
    public function templateUpload()
    {
        $post = $this->request->getPost();
        $certificateId = $post['certificate_id'] ?? null;
        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'certificate_id required'], 422);
        }

        if (!$this->ownsCertificate($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
        }

        $file = $this->request->getFile('template');
        if (!$file || !$file->isValid()) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'template file required'], 422);
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Only PNG/JPG allowed'], 415);
        }

        $certificateDir = 'assets';
        // $targetDir = $this->ensureCertificateDir($certificateDir);

        $newName = 'template_' . $certificateId . '_' . bin2hex(random_bytes(4)) . '_' . time() . '.' . $ext;
        // $file->move($targetDir, $newName);
        $pathDb = $this->s3Service->uploadDocument(
            $file,
            'certificates' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR, // S3 destination folder
            [
                'filename' => $newName, // Specify the filename
                'mimeType' => $file->getMimeType(),
                'metadata' => [
                    'certificate_id' => $certificateId,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ]
            ]
        );
        // $pathDb = 'writable/uploads/certificates/assets/' . $newName;
        $this->certificateModel->setTemplatePath($certificateId, $pathDb['url']);

        // Handle multiple logo files
        $logosSaved = [];
        $logoFiles = $this->request->getFileMultiple('logos');

        if ($logoFiles) {
            foreach ($logoFiles as $logoFile) {
                if ($logoFile->isValid() && $logoFile->getSize() > 0) {
                    $lname = 'logo_' . $certificateId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $logoFile->getExtension();
                    // $logoFile->move($targetDir, $lname);
                    $pathDb = $this->s3Service->uploadDocument(
                        $logoFile,
                        'certificates' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR, // S3 destination folder
                        [
                            'filename' => $lname, // Specify the filename
                            'mimeType' => $file->getMimeType(),
                            'metadata' => [
                                'certificate_id' => $certificateId,
                                'uploaded_at' => date('Y-m-d H:i:s')
                            ]
                        ]
                    );
                    // $logosSaved[] = 'writable/uploads/certificates/assets/' . $lname;
                    $logosSaved[] = $pathDb['url'];
                }
            }
        }

        $files = $this->request->getFiles();
        if ($files) {
            foreach ($files as $key => $f) {
                if ($key === 'template' || $key === 'logos') {
                    continue;
                }

                // Check if it's a single file or array
                if (is_array($f)) {
                    // Handle array of files
                    foreach ($f as $subFile) {
                        if ($subFile && $subFile->isValid() && $subFile->getSize() > 0) {
                            $lname = 'logo_' . $certificateId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $subFile->getExtension();
                            $pathDb = $this->s3Service->uploadDocument(
                                $subFile,
                                'certificates' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR,
                                [
                                    'filename' => $lname,
                                    'mimeType' => $subFile->getMimeType(),
                                    'metadata' => [
                                        'certificate_id' => $certificateId,
                                        'uploaded_at' => date('Y-m-d H:i:s')
                                    ]
                                ]
                            );
                            $logosSaved[] = $pathDb['url'];
                        }
                    }
                } else {
                    // Handle single file
                    if ($f && $f->isValid() && $f->getSize() > 0) {
                        $lname = 'logo_' . $certificateId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $f->getExtension();
                        $pathDb = $this->s3Service->uploadDocument(
                            $f,
                            'certificates' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR,
                            [
                                'filename' => $lname,
                                'mimeType' => $f->getMimeType(),
                                'metadata' => [
                                    'certificate_id' => $certificateId,
                                    'uploaded_at' => date('Y-m-d H:i:s')
                                ]
                            ]
                        );
                        $logosSaved[] = $pathDb['url'];
                    }
                }
            }
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Template uploaded',
            'template_path' => $pathDb['url'],
            'logos' => $logosSaved
        ]);
    }

    public function getTemplateDataAllKeys($certificateId)
    {
        $data = $this->certificateModel->find($certificateId);
        if (!$data) {
            return ['success' => 0, 'status' => false, 'message' => 'Certificate not found', 'data_keys' => []];
        }

        // Check if data_keys exists and is a JSON string
        $dataKeys = [];
        if (isset($data['data_keys'])) {
            if (is_string($data['data_keys'])) {
                // Try to decode JSON string
                $decoded = json_decode($data['data_keys'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $dataKeys = $decoded;
                } else {
                    // If it's already an array or other format
                    $dataKeys = is_array($data['data_keys']) ? $data['data_keys'] : [];
                }
            } elseif (is_array($data['data_keys'])) {
                $dataKeys = $data['data_keys'];
            }
        }

        return [
            'success' => 1,
            'status' => true,
            'message' => 'Template data',
            'data_keys' => $dataKeys
        ];
    }
    /**
     * POST /api/certificate/template/update
     * Payload: { certificate_id, variables: [ {var_key, x, y, font_size?, color?, label?}, ... ] }
     */
    public function templateUpdate()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $certificateId = $data['certificate_id'] ?? null;
        $variables = $data['variables'] ?? null;
        $skipValidation = !empty($data['skip_validation']); // Optional: skip validation

        if (!$certificateId || !is_numeric($certificateId) || !is_array($variables)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'certificate_id and variables required'], 422);
        }

        if (!$this->ownsCertificate($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
        }

        // Get data keys - getTemplateDataAllKeys already handles decoding/array normalization
        $keysResult = $this->getTemplateDataAllKeys($certificateId);
        $dataKeys = $keysResult['data_keys'] ?? [];

        // Debug log
        log_message('debug', 'Data keys type: ' . gettype($dataKeys));
        log_message('debug', 'Data keys: ' . print_r($dataKeys, true));

        // Validate var_keys against available data keys
        $invalidKeys = [];
        $validVariables = [];

        foreach ($variables as $v) {
            if (empty($v['var_key'])) {
                continue;
            }

            $varKey = $v['var_key'];

            // Skip validation if requested or if key is a special template variable
            if ($skipValidation || $this->isSpecialVariable($varKey)) {
                $validVariables[] = $v;
                continue;
            }

            // Check if var_key exists in data keys (must be array for in_array)
            if (is_array($dataKeys) && !in_array($varKey, $dataKeys)) {
                $invalidKeys[] = $varKey;
            } elseif (!is_array($dataKeys)) {
                // If dataKeys is not an array, treat all as invalid (or skip validation)
                if (!$skipValidation) {
                    $invalidKeys[] = $varKey . ' (data keys not available)';
                } else {
                    $validVariables[] = $v;
                }
            } else {
                $validVariables[] = $v;
            }
        }

        // If there are invalid keys, return error
        if (!empty($invalidKeys) && !$skipValidation) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Some variable keys do not exist in certificate data',
                'invalid_keys' => $invalidKeys,
                'available_keys' => is_array($dataKeys) ? $dataKeys : [],
                'suggestion' => 'Use skip_validation=true to bypass this check'
            ], 422);
        }

        // Process valid variables
        $count = 0;
        $savedVariables = [];

        foreach ($validVariables as $v) {
            $varKey = $v['var_key'];
            $props = [
                'label' => $v['label'] ?? null,
                'x' => isset($v['x']) ? (int) $v['x'] : 0,
                'y' => isset($v['y']) ? (int) $v['y'] : 0,
                'font_size' => isset($v['font_size']) ? (int) $v['font_size'] : 12,
                'font_family' => $v['font_family'] ?? 'Arial',
                'color' => $v['color'] ?? '#000000',
                'align' => $v['align'] ?? 'left',
                'order_index' => $v['order_index'] ?? 0,
                'options' => isset($v['options']) ? json_encode($v['options']) : null
            ];

            $result = $this->templateVarModel->upsertVar((int) $certificateId, $varKey, $props);
            if ($result) {
                $count++;
                $savedVariables[] = $varKey;
            }
        }

        $response = [
            'success' => 1,
            'status' => true,
            'message' => 'Template variables saved',
            'saved_count' => $count,
            'saved_variables' => $savedVariables,
            'certificate_id' => (int) $certificateId
        ];

        if (!empty($invalidKeys) && $skipValidation) {
            $response['warning'] = 'Some keys were saved without validation';
            $response['invalid_keys_accepted'] = $invalidKeys;
        }

        if ($count < count($variables)) {
            $response['warning'] = 'Some variables were not processed';
        }

        return $this->respond($response);
    }

    /**
     * GET /api/certificate/template/variables/(:num)
     * Get all template variables for a certificate
     */
    public function getVariables($certificateId)
    {
        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'certificate_id required'], 422);
        }

        if (!$this->certificateModel->find($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Certificate not found'], 404);
        }

        if (!$this->ownsCertificate($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
        }

        $variables = $this->templateVarModel->listByCertificate((int) $certificateId);

        // Decode options if they exist
        foreach ($variables as &$v) {
            if (!empty($v['options'])) {
                $v['options'] = json_decode($v['options'], true);
            }
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'variables' => $variables
        ]);
    }
    /**
     * Check if variable is a special template variable
     */
    private function isSpecialVariable($varKey)
    {
        $specialVariables = [
            'certificate_number',
            'certificate_id',
            'issue_date',
            'expiry_date',
            'verification_code',
            'qr_code',
            'signature',
            'logo',
            'background',
            'watermark',
            'serial_number',
            'generated_date'
        ];

        // Also check for patterns
        $specialPatterns = [
            '/^date_/i',
            '/^id_/i',
            '/^no_/i',
            '/^serial_/i',
            '/^code_/i',
            '/_date$/i',
            '/_id$/i',
            '/_no$/i'
        ];

        if (in_array($varKey, $specialVariables)) {
            return true;
        }

        foreach ($specialPatterns as $pattern) {
            if (preg_match($pattern, $varKey)) {
                return true;
            }
        }

        return false;
    }
    /**
     * POST /api/certificate/template/upload/data
     * Two-step process:
     * 1. First call: Upload sheet with type=validate → Returns extracted JSON data
     * 2. Second call: Send JSON data directly with type=submit → Saves to DB
     */

    public function templateUploadData()
    {
        // Check if request is JSON (for submit mode) - only parse if Content-Type is application/json
        $contentType = $this->request->getHeaderLine('Content-Type');
        $isJsonRequest = strpos($contentType, 'application/json') !== false;

        if ($isJsonRequest) {
            try {
                $json = $this->request->getJSON(true);
            } catch (\Exception $e) {
                // Invalid JSON
                return $this->respond([
                    'success' => 0,
                    'status' => false,
                    'message' => 'Invalid JSON format: ' . $e->getMessage()
                ], 400);
            }

            // Mode 1: Direct JSON submission (type=submit with JSON data)
            if (!empty($json) && isset($json['type']) && $json['type'] === 'submit') {

                $certificateId = $json['certificate_id'] ?? null;
                if ($certificateId && is_numeric($certificateId)) {
                    $this->cleanupOldFiles($certificateId);
                }
                $data = $json['data'] ?? [];
                $source = $json['source_file'] ?? null; // Optional: original file path

                if (!$certificateId || !is_numeric($certificateId)) {
                    return $this->respond(['success' => 0, 'status' => false, 'message' => 'certificate_id required'], 422);
                }

                if (!$this->ownsCertificate($certificateId)) {
                    return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
                }

                if (empty($data) || !is_array($data)) {
                    return $this->respond(['success' => 0, 'status' => false, 'message' => 'Data array required'], 422);
                }

                $inserted = 0;
                $failedRows = [];
                $successIds = [];

                foreach ($data as $index => $row) {
                    if (is_array($row) && !empty($row)) {
                        try {
                            // Remove any internal keys that shouldn't be in database
                            $cleanRow = $row;
                            unset($cleanRow['_row_number'], $cleanRow['_validation_errors']);

                            $id = $this->dataModel->insertRow($certificateId, $cleanRow);
                            if ($id) {
                                $inserted++;
                                $successIds[] = $id;
                            }
                        } catch (\Exception $e) {
                            $failedRows[] = [
                                'index' => $index,
                                'row' => $row,
                                'error' => $e->getMessage()
                            ];
                            log_message('error', 'Row insert failed: ' . $e->getMessage());
                        }
                    }
                }

                // If source file was provided in JSON, clean it up
                if ($source && file_exists(WRITEPATH . $source)) {
                    $this->cleanupSourceFile($source);
                }
                $dataKeysRes = $this->certificateModel->updateDataKeys($certificateId, $json['headers'] ?? []);

                $response = [
                    'success' => 1,
                    'status' => true,
                    'message' => 'Data submitted successfully',
                    'rows_inserted' => $inserted,
                    'rows_failed' => count($failedRows),
                    'inserted_ids' => $successIds,
                    'type' => 'submit',
                    'dataKeysRes' => $dataKeysRes,
                ];

                if (!empty($failedRows)) {
                    $response['failed_rows'] = $failedRows;
                    $response['message'] = 'Data submitted with some errors';
                }

                if ($source) {
                    $response['source_file'] = $source;
                    $response['source_file_cleaned'] = true;
                }

                return $this->respond($response, 201);
            }

            // Mode 2: Single JSON row submission (legacy)
            if (!empty($json['certificate_id']) && !empty($json['row']) && is_array($json['row'])) {
                $certificateId = (int) $json['certificate_id'];
                if ($certificateId && is_numeric($certificateId)) {
                    $this->cleanupOldFiles($certificateId);
                }
                if (!$this->ownsCertificate($certificateId)) {
                    return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
                }
                $row = $json['row'];
                $id = $this->dataModel->insertRow($certificateId, $row);
                return $this->respond(['success' => 1, 'status' => true, 'message' => 'Data row added', 'data_id' => $id], 201);
            }

            // If it's a JSON request but doesn't match any mode, return error
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Invalid JSON structure. For file upload, use multipart/form-data'
            ], 400);
        }

        // If not JSON request, assume it's file upload (multipart/form-data)
        // Mode 3: File upload (type=validate or type=submit)
        $certificateId = $this->request->getPost('certificate_id') ?? null;
        $type = $this->request->getPost('type') ?? 'validate'; // Default to validate
        $skipRows = $this->request->getPost('skip_rows') ?? 0; // Rows to skip from top
        $sheetIndex = $this->request->getPost('sheet_index') ?? 0; // Sheet index to read

        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'certificate_id required'], 422);
        }

        if (!$this->ownsCertificate($certificateId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
        }

        $file = $this->request->getFile('data_sheet');
        if (!$file || !$file->isValid()) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'data_sheet file required'], 422);
        }

        $ext = strtolower($file->getExtension());
        $allowedExt = ['csv', 'txt', 'json', 'xls', 'xlsx', 'xlsm', 'ods'];

        if (!in_array($ext, $allowedExt)) {
            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Allowed files: CSV, JSON, Excel (xls, xlsx, xlsm, ods)'
            ], 415);
        }

        // Get file info BEFORE moving the file
        $fileInfo = [
            'name' => $file->getName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
            'extension' => $ext
        ];

        $dirCertificates = 'certificates';
        // Prepare directory
        $targetDir = $this->ensureCertificateDir($dirCertificates) . 'data' . DIRECTORY_SEPARATOR;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $fileName = 'data_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $file->move($targetDir, $fileName);
        $storedPath = 'writable/uploads/certificates/data/' . $fileName;
        $filePath = $targetDir . $fileName;

        // Track if we should delete the stored file
        $shouldDeleteStoredFile = false;

        try {
            // Check if PhpSpreadsheet is installed
            if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
                $shouldDeleteStoredFile = true; // Delete invalid file
                return $this->respond([
                    'success' => 0,
                    'status' => false,
                    'message' => 'PhpSpreadsheet library not installed. Please run: composer require phpoffice/phpspreadsheet'
                ], 500);
            }

            // Extract data using PhpSpreadsheet for all file types
            list($extractedData, $headers, $sheetsInfo) = $this->extractDataWithPhpSpreadsheet(
                $filePath,
                $ext,
                $skipRows,
                $sheetIndex
            );

            // If no data extracted
            if (empty($extractedData)) {
                $shouldDeleteStoredFile = true; // Delete empty file
                return $this->respond([
                    'success' => 0,
                    'status' => false,
                    'message' => 'No valid data found in the file',
                    'file' => $storedPath,
                    'headers' => $headers
                ], 422);
            }

            // If type=submit, save directly to DB
            if ($type === 'submit') {
                $inserted = 0;
                $failedRows = [];
                $successIds = [];

                foreach ($extractedData as $index => $row) {
                    if (is_array($row) && !empty($row)) {
                        try {
                            // Remove internal field
                            $cleanRow = $row;
                            unset($cleanRow['_source_row']);

                            $id = $this->dataModel->insertRow($certificateId, $cleanRow);
                            if ($id) {
                                $inserted++;
                                $successIds[] = $id;
                            }
                        } catch (\Exception $e) {
                            $failedRows[] = [
                                'row_index' => $index + $skipRows + 1,
                                'data' => $row,
                                'error' => $e->getMessage()
                            ];
                        }
                    }
                }

                // Delete stored file after successful processing in submit mode
                $shouldDeleteStoredFile = true;

                // Update data keys in certificate table
                $this->certificateModel->updateDataKeys($certificateId, $headers);

                $response = [
                    'success' => 1,
                    'status' => true,
                    'message' => $failedRows ? 'Data uploaded with some errors' : 'Data uploaded successfully',
                    'file' => $storedPath,
                    'rows_total' => count($extractedData),
                    'rows_inserted' => $inserted,
                    'rows_failed' => count($failedRows),
                    'inserted_ids' => $successIds,
                    'file_deleted' => $shouldDeleteStoredFile,
                    'type' => 'submit'
                ];

                if (!empty($failedRows)) {
                    $response['failed_rows'] = $failedRows;
                }

                return $this->respond($response, 201);
            }

            // type=validate: Return extracted JSON data
            // Remove internal fields for cleaner JSON output
            $cleanData = [];
            foreach ($extractedData as $row) {
                $cleanRow = $row;
                unset($cleanRow['_source_row']);
                $cleanData[] = $cleanRow;
            }

            // For validate mode, we keep the file for potential submit later
            $shouldDeleteStoredFile = true;

            // Update data keys in certificate table even on validate 
            // so the template editor knows which keys are available
            $this->certificateModel->updateDataKeys($certificateId, $headers);

            return $this->respond([
                'success' => 1,
                'status' => true,
                'message' => 'Data extracted successfully',
                'file' => $storedPath,
                'file_info' => $fileInfo,
                'extraction_info' => [
                    'rows_extracted' => count($cleanData),
                    'total_columns' => count($headers),
                    'sheets_found' => $sheetsInfo['total_sheets'],
                    'sheet_used' => $sheetsInfo['sheet_used'],
                    'skip_rows_applied' => $skipRows
                ],
                'json_format' => [
                    'headers' => $headers,
                    'type' => 'submit',
                    'certificate_id' => (int) $certificateId,
                    'data' => $cleanData,
                    'source_file' => $storedPath,
                    'total_rows' => count($cleanData)
                ],
                'file_retention' => 'File kept for potential submit. Will be auto-deleted after 24 hours.',
                'type' => 'validate'
            ], 200);

        } catch (\Exception $e) {
            // Delete stored file on error
            $shouldDeleteStoredFile = true;

            log_message('error', 'Data extraction error: ' . $e->getMessage());

            return $this->respond([
                'success' => 0,
                'status' => false,
                'message' => 'Error processing file: ' . $e->getMessage(),
                'file' => $storedPath,
                'error_details' => (ENVIRONMENT === 'development') ? $e->getTraceAsString() : null
            ], 500);
        } finally {
            // Clean up: Delete the stored file if needed
            if ($shouldDeleteStoredFile && file_exists($filePath)) {
                try {
                    if (unlink($filePath)) {
                        log_message('info', 'Stored file deleted: ' . $filePath);
                    } else {
                        log_message('warning', 'Failed to delete stored file: ' . $filePath);
                    }
                } catch (\Exception $e) {
                    log_message('error', 'Error deleting stored file: ' . $e->getMessage());
                }
            }
        }
    }

    // Add a cleanup method to delete old files
    private function cleanupOldFiles($certificateId, $retentionHours = 24)
    {
        $dataDir = $this->ensureCertificateDir($certificateId) . 'data' . DIRECTORY_SEPARATOR;

        if (!is_dir($dataDir)) {
            return;
        }

        $files = glob($dataDir . 'data_*');
        $now = time();
        $deletedCount = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                // Check if file is older than retention period
                if ($now - filemtime($file) > ($retentionHours * 3600)) {
                    if (unlink($file)) {
                        $deletedCount++;
                        log_message('info', 'Cleaned up old file: ' . $file);
                    }
                }
            }
        }

        if ($deletedCount > 0) {
            log_message('info', "Cleaned up $deletedCount old files for certificate $certificateId");
        }
    }

    // Cleanup source file from JSON submit
    private function cleanupSourceFile($sourcePath)
    {
        // Remove 'writable/' prefix if present
        $cleanPath = str_replace('writable/', '', $sourcePath);
        $fullPath = WRITEPATH . $cleanPath;

        if (file_exists($fullPath)) {
            if (unlink($fullPath)) {
                log_message('info', 'Source file cleaned up: ' . $fullPath);
                return true;
            } else {
                log_message('warning', 'Failed to clean up source file: ' . $fullPath);
            }
        }
        return false;
    }
    /**
     * Extract data using PhpSpreadsheet (handles all file types)
     */
    private function extractDataWithPhpSpreadsheet($filePath, $extension, $skipRows = 0, $sheetIndex = 0)
    {
        $extractedData = [];
        $headers = [];
        $sheetsInfo = [
            'total_sheets' => 0,
            'sheet_names' => [],
            'sheet_used' => null
        ];

        // Load the spreadsheet
        $spreadsheet = IOFactory::load($filePath);

        // Get sheet info
        $sheetsInfo['total_sheets'] = $spreadsheet->getSheetCount();
        $sheetNames = $spreadsheet->getSheetNames();
        $sheetsInfo['sheet_names'] = $sheetNames;

        // Get the specific sheet
        if ($sheetIndex >= 0 && $sheetIndex < count($sheetNames)) {
            $worksheet = $spreadsheet->getSheet($sheetIndex);
            $sheetsInfo['sheet_used'] = $sheetNames[$sheetIndex];
        } else {
            $worksheet = $spreadsheet->getActiveSheet();
            $sheetsInfo['sheet_used'] = $worksheet->getTitle();
        }

        // Get highest row and column
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        // If skipRows is 0, try to auto-detect header row
        $headerRowIndex = $skipRows > 0 ? $skipRows : 1;

        // Extract headers
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellCoordinate = Coordinate::stringFromColumnIndex($col) . $headerRowIndex;
            $cell = $worksheet->getCell($cellCoordinate);
            $value = $cell->getValue();

            // Clean header name
            $headerName = $this->cleanHeaderName($value, $col);
            $headers[] = $headerName;
        }

        // Extract data rows (starting from row after headers)
        $dataStartRow = $headerRowIndex + 1;

        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $rowData = [];
            $hasData = false;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellCoordinate = Coordinate::stringFromColumnIndex($col) . $row;
                $cell = $worksheet->getCell($cellCoordinate);
                $value = $cell->getValue();

                // Handle rich text
                if ($value instanceof RichText) {
                    $value = $value->getPlainText();
                }

                // Convert date/time values
                if (Date::isDateTime($cell)) {
                    try {
                        $value = Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        // Keep original value if conversion fails
                    }
                }

                // Clean the value
                $value = $this->cleanCellValue($value);

                $headerName = $headers[$col - 1] ?? "column_$col";
                $rowData[$headerName] = $value;

                // Check if row has any non-empty data
                if (!empty(trim($value ?? ''))) {
                    $hasData = true;
                }
            }

            // Only add row if it has data
            if ($hasData) {
                $rowData['_source_row'] = $row;
                $extractedData[] = $rowData;
            }
        }

        // If no headers found, create generic ones
        if (empty(array_filter($headers))) {
            $headers = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $headers[] = "column_$col";
            }
        }

        return [$extractedData, $headers, $sheetsInfo];
    }

    /**
     * Get PhpSpreadsheet reader type for file extension
     */
    private function getReaderType($extension)
    {
        $map = [
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            'xlsm' => 'Xlsx', // Xlsx reader handles xlsm
            'ods' => 'Ods',
            'csv' => 'Csv',
            'txt' => 'Csv',
            'pdf' => 'Html', // PDF requires additional library
        ];

        return $map[$extension] ?? 'Xlsx';
    }

    /**
     * Clean header name
     */
    private function cleanHeaderName($name, $colIndex)
    {
        if (empty($name)) {
            return "field_$colIndex";
        }

        // Remove special characters, keep letters, numbers, spaces, underscores
        $name = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '', $name);

        // Trim and replace spaces with underscore
        $name = trim($name);
        $name = preg_replace('/\s+/', '_', $name);

        // Ensure it's not empty
        if (empty($name)) {
            return "field_$colIndex";
        }

        // Convert to lowercase for consistency
        return strtolower($name);
    }

    /**
     * Clean cell value
     */
    private function cleanCellValue($value)
    {
        if ($value === null) {
            return '';
        }

        // Convert to string
        $value = (string) $value;

        // Trim whitespace
        $value = trim($value);

        // Replace multiple spaces with single space
        $value = preg_replace('/\s+/', ' ', $value);

        return $value;
    }

    /**
     * Generate JSON format for submit
     */
    private function generateJsonFormatForSubmit($extractedData, $certificateId, $filePath)
    {
        // Remove internal fields from data
        $cleanData = [];
        foreach ($extractedData as $row) {
            $cleanRow = $row;
            unset($cleanRow['_source_row']);
            $cleanData[] = $cleanRow;
        }

        return [
            'type' => 'submit',
            'certificate_id' => (int) $certificateId,
            'data' => $cleanData,
            'source_file' => $filePath,
            'total_rows' => count($cleanData)
        ];
    }


    /**
     * POST /api/certificate/generate
     * Payload: { certificate_id, data_id?, data_ids? (array), data_rows? (array), generate_all? (bool) }
     * Generates actual PDF certificates with template and data using mPDF
     */
    // public function generate()
    // {
    //     $data = $this->request->getJSON(true) ?? $this->request->getPost();
    //     $certificateId = $data['certificate_id'] ?? null;
    //     $paperSize = $data['paper_size'] ?? 'A4'; // A4, A4-L, Letter, Legal
    //     $quality = $data['quality'] ?? 'high'; // high, medium, low
    //     $outputType = $data['output'] ?? 'pdf'; // pdf, download, stream, file

    //     if (!$certificateId || !is_numeric($certificateId)) {
    //         return $this->respond(['success' => 0, 'status' => false, 'message' => 'certificate_id required'], 422);
    //     }

    //     if (!$this->checkCertificateExistsAndAccess($certificateId)) {
    //         return $this->respond(['success' => 0, 'status' => false, 'message' => 'Certificate not found or access denied'], 403);
    //     }
    //     // Get certificate data rows
    //     $rows = [];
    //     $sourceDataIds = [];

    //     // Get data based on input
    //     if (!empty($data['data_id']) && is_numeric($data['data_id'])) {
    //         $d = $this->dataModel->find((int) $data['data_id']);
    //         if ($d && $d['certificate_id'] == $certificateId) {
    //             $rowData = json_decode($d['data'], true);
    //             if (is_array($rowData)) {
    //                 $rows[] = $rowData;
    //                 $sourceDataIds[] = $d['id'];
    //             }
    //         }
    //     } elseif (!empty($data['data_ids']) && is_array($data['data_ids'])) {
    //         foreach ($data['data_ids'] as $dataId) {
    //             if (is_numeric($dataId)) {
    //                 $d = $this->dataModel->find((int) $dataId);
    //                 if ($d && $d['certificate_id'] == $certificateId) {
    //                     $rowData = json_decode($d['data'], true);
    //                     if (is_array($rowData)) {
    //                         $rows[] = $rowData;
    //                         $sourceDataIds[] = $d['id'];
    //                     }
    //                 }
    //             }
    //         }
    //     } elseif (!empty($data['data_rows']) && is_array($data['data_rows'])) {
    //         $rows = $data['data_rows'];
    //     } else {
    //         // Generate for all data (default)
    //         $allData = $this->dataModel->where('certificate_id', $certificateId)->findAll();

    //         foreach ($allData as $d) {
    //             if (!empty($d['data'])) {
    //                 $rowData = json_decode($d['data'], true);
    //                 if (is_array($rowData)) {
    //                     $rows[] = $rowData;
    //                     $sourceDataIds[] = $d['id'];
    //                 }
    //             }
    //         }
    //     }
    //     if (empty($rows)) {
    //         return $this->respond(['success' => 0, 'status' => false, 'message' => 'No data found for generation'], 404);
    //     }

    //     // Get certificate template info
    //     $certificate = $this->certificateModel->find($certificateId);

    //     if (!$certificate) {
    //         return $this->respond(['success' => 0, 'status' => false, 'message' => 'Certificate not found'], 404);
    //     }

    //     // Get template variables
    //     $templateVars = $this->templateVarModel->where('certificate_id', $certificateId)->findAll();

    //     // Prepare directory
    //     $targetDir = $this->ensureCertificateDir('data/' . $certificateId) . 'generated' . DIRECTORY_SEPARATOR;
    //     if (!is_dir($targetDir)) {
    //         mkdir($targetDir, 0755, true);
    //     }

    //     $generatedIds = [];
    //     $generatedFiles = [];
    //     $orientation = $certificate['orientation'] ?? null;
    //     foreach ($rows as $index => $rowData) {
    //         if ($certificate['certificate_code'] == 1) {
    //             if (!isset($rowData['certificate_number']) || $rowData['certificate_number'] == null) {
    //                 $certNumber = 'CERT-' . date('Ymd') . '-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);
    //                 $rowData['certificate_number'] = $certNumber;
    //                 $generatedFiles[] = [
    //                     'certificate_number' => $certNumber,
    //                 ];
    //             } else {
    //                 $rowData['certificate_number'];
    //             }
    //         }
    //         $rowData['issue_date'] = date('F j, Y');
    //         $rowData['certificate_id'] = $certificateId;
    //         $rowData['certificate_name'] = $certificate['name'] ?? 'Certificate';

    //         // Generate PDF file
    //         $fileName = 'certificate_' . $certificateId . '_' . ($index + 1) . '_' . time() . '.pdf';
    //         $filePath = $targetDir . $fileName;
    //         $absolutePath = str_starts_with($filePath, WRITEPATH)
    //             ? $filePath
    //             : WRITEPATH . ltrim($filePath, '/');
    //         $dir = dirname($absolutePath);
    //         if (!is_dir($dir)) {
    //             mkdir($dir, 0775, true);
    //         }



    //         // Create PDF using mPDF
    //         $pdfCreated = $this->generateCertificateWithTCPDF($absolutePath, $certificate, $templateVars, $rowData, $paperSize, $quality, $orientation);
    //         if (!$pdfCreated) {
    //             log_message('error', 'Failed to generate PDF for certificate ' . $certificateId);
    //             continue;
    //         }

    //         $relativePath = 'writable/uploads/certificates/data/' . $certificateId . '/generated/' . $fileName;
    //         $sourceDataId = $sourceDataIds[$index] ?? $data['data_id'] ?? null;
    //         $rowData['filename'] = $fileName;
    //         // Save to generated_certificates table
    //         $genId = $this->generatedModel->createGenerated((int) $certificateId, $relativePath, $rowData, $sourceDataId);

    //         if ($genId) {
    //             $generatedIds[] = $genId;
    //             $generatedFiles[] = [
    //                 'id' => $genId,
    //                 'file_path' => $relativePath,
    //                 'file_url' => base_url($relativePath),
    //                 'file_size' => filesize($absolutePath),
    //                 'download_url' => base_url('api/certificate/download/' . $genId)
    //             ];
    //         }
    //     }



    //     // Create ZIP if multiple certificates
    //     $zipFile = null;
    //     if (count($generatedFiles) > 1) {
    //         $zipFile = $this->createZipArchive($generatedFiles, $certificateId, $targetDir);
    //     }

    //     // Update certificate status
    //     if (!empty($generatedIds)) {
    //         $this->certificateModel->markGenerated((int) $certificateId, $zipFile);
    //     }

    //     // Direct download if single certificate
    //     if ($outputType === 'download' && count($generatedFiles) === 1) {
    //         return $this->downloadGeneratedCertificate($generatedIds[0]);
    //     }

    //     // Stream single PDF
    //     if ($outputType === 'stream' && count($generatedFiles) === 1) {
    //         return $this->streamGeneratedCertificate($generatedIds[0]);
    //     }

    //     return $this->respond([
    //         'success' => 1,
    //         'status' => true,
    //         'message' => count($generatedIds) . ' certificate(s) generated successfully',
    //         'data' => [
    //             'generated_ids' => $generatedIds,
    //             'generated_count' => count($generatedIds),
    //             'certificate_id' => (int) $certificateId,
    //             'certificate_name' => $certificate['name'],
    //             'files' => $generatedFiles,
    //             'zip_file' => $zipFile ? [
    //                 'path' => $zipFile,
    //                 'url' => base_url($zipFile),
    //                 'download_url' => base_url('api/certificate/download/zip/' . $certificateId . '?token=' . bin2hex(random_bytes(8)))
    //             ] : null,
    //             'download_all_url' => count($generatedFiles) > 1 ?
    //                 base_url('api/certificate/download/all/' . $certificateId) : null
    //         ]
    //     ], 201);
    // }

    public function generate()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $certificateId = $data['certificate_id'] ?? null;
        $paperSize = $data['paper_size'] ?? 'A4';
        $quality = $data['quality'] ?? 'high';
        $outputType = $data['output'] ?? 'pdf';

        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'certificate_id required'], 422);
        }

        if (!$this->checkCertificateExistsAndAccess($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Access denied'], 403);
        }

        /* -------------------------------------------------
         * Collect source data
         * ------------------------------------------------- */
        $rows = [];
        $sourceDataIds = [];

        if (!empty($data['data_id'])) {
            $d = $this->dataModel->find((int) $data['data_id']);
            if ($d && $d['certificate_id'] == $certificateId) {
                $rows[] = json_decode($d['data'], true);
                $sourceDataIds[] = $d['id'];
            }
        } elseif (!empty($data['data_ids']) && is_array($data['data_ids'])) {
            foreach ($data['data_ids'] as $id) {
                $d = $this->dataModel->find((int) $id);
                if ($d && $d['certificate_id'] == $certificateId) {
                    $rows[] = json_decode($d['data'], true);
                    $sourceDataIds[] = $d['id'];
                }
            }
        } else {
            $allData = $this->dataModel->where('certificate_id', $certificateId)->findAll();
            foreach ($allData as $d) {
                $rows[] = json_decode($d['data'], true);
                $sourceDataIds[] = $d['id'];
            }
        }

        if (empty($rows)) {
            return $this->respond(['success' => 0, 'message' => 'No data found'], 404);
        }

        /* -------------------------------------------------
         * Certificate + template
         * ------------------------------------------------- */
        $certificate = $this->certificateModel->find($certificateId);
        $templateVars = $this->templateVarModel
            ->where('certificate_id', $certificateId)
            ->findAll();

        $orientation = $certificate['orientation'] ?? 'L';

        /* -------------------------------------------------
         * Temp directory (ONLY local usage)
         * ------------------------------------------------- */
        $tmpDir = WRITEPATH . 'tmp/';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $generatedIds = [];
        $generatedFiles = [];
        $validDataRows = []; // To store data for combined PDF
        $pdfService = new CertificatePDFService();

        // --- Batch Identification ---
        $batchId = 'batch_' . date('Ymd_His');
        $slug = $this->getCertificateSlug($certificate);
        $folder = "certificates/data/generated/{$slug}/{$batchId}";

        /* -------------------------------------------------
         * Generate certificates
         * ------------------------------------------------- */
        foreach ($rows as $i => $rowData) {

            if (empty($rowData['certificate_number'])) {
                $rowData['certificate_number'] =
                    'CERT-' . date('Ymd') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            }

            $rowData['issue_date'] = date('F j, Y');
            $rowData['certificate_id'] = $certificateId;

            $fileName = 'certificate_row_' . ($sourceDataIds[$i] ?? 'new') . '_' . uniqid() . '.pdf';
            $tempPdfPath = $tmpDir . $fileName;

            /* ---------- Generate PDF locally ---------- */
            $pdfContent = $pdfService->generate($certificate, $templateVars, $rowData, [
                'paperSize' => $paperSize,
                'quality' => $quality
            ]);

            if (!$pdfContent) {
                log_message('error', 'PDF generation failed');
                continue;
            }

            file_put_contents($tempPdfPath, $pdfContent);
            $ok = file_exists($tempPdfPath);

            if (!$ok || !file_exists($tempPdfPath)) {
                log_message('error', 'PDF generation failed');
                continue;
            }

            /* ---------- Upload to S3 ---------- */
            $s3Key = "{$folder}/{$fileName}";

            $s3Url = $this->s3Service->uploadLocalFile(
                $tempPdfPath,
                $s3Key,
                [
                    'ContentType' => 'application/pdf',
                    'Metadata' => [
                        'certificate_id' => (string) $certificateId,
                        'certificate_no' => $rowData['certificate_number'],
                    ],
                ]
            );

            unlink($tempPdfPath); // 🚨 important

            /* ---------- Save DB ---------- */
            $genId = $this->generatedModel->createGenerated(
                (int) $certificateId,
                $s3Key,
                $rowData,
                $sourceDataIds[$i] ?? null,
                $s3Url,
                $batchId
            );

            // Also update the source data row with the certificate path/url
            if (!empty($sourceDataIds[$i])) {
                try {
                    $this->dataModel->update($sourceDataIds[$i], [
                        'file_path' => $s3Key,
                        'download_url' => $s3Url
                    ]);
                } catch (\Exception $e) {
                    // Log the error but don't stop generation if columns are missing
                    log_message('error', 'Failed to update certificate_data row: ' . $e->getMessage());
                }
            }

            $generatedIds[] = $genId;
            $generatedFiles[] = [
                'id' => $genId,
                'file_key' => $s3Key,
                'file_url' => $s3Url,
                'download_url' => $this->s3Service->presignedUrl($s3Key),
            ];
            $validDataRows[] = $rowData; // For combined PDF
        }

        /* -------------------------------------------------
         * NEW: Generate Combined PDF
         * ------------------------------------------------- */
        $combinedPdfUrl = null;
        if (!empty($validDataRows)) {
            $combinedContent = $pdfService->generateBulk($certificate, $templateVars, $validDataRows);
            if ($combinedContent) {
                $compName = 'combined_' . $certificateId . '_' . time() . '.pdf';
                $compKey = "{$folder}/{$compName}";
                try {
                    // Use S3Service directly to upload text/binary content
                    $compRes = $this->s3Service->uploadDocument($combinedContent, $folder, [
                        'filename' => $compName,
                        'mimeType' => 'application/pdf',
                        'isPublic' => true
                    ]);
                    if ($compRes && isset($compRes['url'])) {
                        $combinedPdfUrl = $compRes['url'];
                    }
                } catch (\Exception $e) {
                    log_message('error', 'Failed to upload combined PDF: ' . $e->getMessage());
                }
            }
        }

        /* -------------------------------------------------
         * NEW: Trigger ZIP Job
         * ------------------------------------------------- */
        $zipJobId = null;
        if (count($generatedIds) > 1) {
            $zipJobModel = new CertificateZipJobModel();
            $zipJobId = $zipJobModel->createJob((int) $certificateId, count($generatedIds), $folder);
        }

        // Update certificate last generated info
        if ($combinedPdfUrl) {
            $this->certificateModel->update($certificateId, [
                'last_generated_url' => $combinedPdfUrl,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        /* -------------------------------------------------
         * NEW: S3 Cleanup (Keep Last 3)
         * ------------------------------------------------- */
        $this->cleanupOldBatches($certificateId, $slug);


        /* -------------------------------------------------
         * Response
         * ------------------------------------------------- */
        return $this->respond([
            'success' => 1,
            'message' => count($generatedIds) . ' certificate(s) generated',
            'data' => [
                'certificate_id' => (int) $certificateId,
                'batch_id' => $batchId,
                'generated_ids' => $generatedIds,
                'combined_pdf_url' => $combinedPdfUrl,
                'zip_job_id' => $zipJobId,
                'files' => $generatedFiles,
            ]
        ], 201);
    }


    /**
     * Generate PDF certificate using TCPDF
     */
    private function generateCertificateWithTCPDF($filePath, $certificate, $templateVars, $data, $paperSize = 'A4', $quality = 'high', $orientation = 'L')
    {
        try {
            // Load TCPDF if not already loaded
            if (!class_exists('TCPDF')) {
                // Try to load TCPDF from vendor
                $tcpdfPath = ROOTPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php';
                if (file_exists($tcpdfPath)) {
                    require_once $tcpdfPath;
                } else {
                    throw new \Exception('TCPDF library not found. Please install via: composer require tecnickcom/tcpdf');
                }
            }

            // Get template image path
            $templateImage = null;
            if (!empty($certificate['template_path'])) {
                $templatePath = $certificate['template_path'];

                $templatePath = $certificate['template_path'];

                if (filter_var($templatePath, FILTER_VALIDATE_URL)) {
                    // URL → do NOT use file_exists
                    $templateImage = $templatePath;
                } else {
                    // Local file
                    $fullTemplatePath = WRITEPATH . str_replace('writable/', '', $templatePath);

                    if (!file_exists($fullTemplatePath)) {
                        throw new \Exception('Template image not found: ' . $fullTemplatePath);
                    }

                    $templateImage = $fullTemplatePath;
                }

            } else {
                throw new \Exception('No template image specified for certificate');
            }

            // Check if image is valid
            $imageInfo = @getimagesize($templateImage);
            if (!$imageInfo) {
                throw new \Exception('Invalid template image or unsupported format: ' . $templateImage);
            }

            // Image dimensions in pixels
            $imgWidth = $imageInfo[0];
            $imgHeight = $imageInfo[1];
            $originalWidth = $imgWidth; // Save original dimensions
            $originalHeight = $imgHeight;
            $imageType = $imageInfo[2];

            // Supported image types
            $supportedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];
            if (!in_array($imageType, $supportedTypes)) {
                throw new \Exception('Unsupported image type. Use JPEG, PNG, or GIF.');
            }

            // Scale down very large images
            $maxPixels = 2000; // Maximum dimension
            if ($imgWidth > $maxPixels || $imgHeight > $maxPixels) {
                $scale = $maxPixels / max($imgWidth, $imgHeight);
                $imgWidth = intval($imgWidth * $scale);
                $imgHeight = intval($imgHeight * $scale);
            }

            // Convert pixels to millimeters for PDF page size
            $dpi = 96;
            $pixelsToMm = 25.4 / $dpi;

            $pageWidthMm = $imgWidth * $pixelsToMm;
            $pageHeightMm = $imgHeight * $pixelsToMm;

            // Debug: Show dimensions
            log_message('debug', "Original image: {$originalWidth}x{$originalHeight} pixels");
            log_message('debug', "Scaled image: {$imgWidth}x{$imgHeight} pixels");
            log_message('debug', "PDF page size: {$pageWidthMm}x{$pageHeightMm} mm");

            // Create custom page size based on scaled template dimensions
            $customPageSize = array($pageWidthMm, $pageHeightMm);

            // Create TCPDF instance with custom page size
            $pdf = new TCPDF($orientation, 'mm', $customPageSize, true, 'UTF-8', false);

            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // Set margins to 0
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            // Add page
            $pdf->AddPage();

            // Get actual page dimensions
            $pageWidth = $pdf->getPageWidth();
            $pageHeight = $pdf->getPageHeight();

            // Add background image (full page - will be scaled by TCPDF)
            $pdf->Image($templateImage, 0, 0, $pageWidth, $pageHeight, '', '', '', false, 300, '', false, false, 0);

            // Map font families to TCPDF built-in fonts
            $fontMap = [
                'Arial' => 'helvetica',      // Helvetica is similar to Arial
                'Helvetica' => 'helvetica',
                'Times' => 'times',
                'Times New Roman' => 'times',
                'Courier' => 'courier',
                'Courier New' => 'courier',
                'Symbol' => 'symbol',
                'ZapfDingbats' => 'zapfdingbats'
            ];

            // Helper function to convert hex color to RGB
            $hex2rgb = function ($hex) {
                $hex = str_replace('#', '', $hex);
                if (strlen($hex) == 3) {
                    $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
                    $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
                    $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
                } else {
                    $r = hexdec(substr($hex, 0, 2));
                    $g = hexdec(substr($hex, 2, 2));
                    $b = hexdec(substr($hex, 4, 2));
                }
                return [$r, $g, $b];
            };

            // Add certificate variables
            foreach ($templateVars as $var) {
                $varKey = $var['var_key'];
                $value = isset($data[$varKey]) ? (string) $data[$varKey] : '';

                if (empty($value)) {
                    continue;
                }

                // Format date fields
                if (strpos($varKey, 'date') !== false || $varKey === 'date_joined') {
                    try {
                        $date = new \DateTime($value);
                        $value = $date->format('F j, Y');
                    } catch (\Exception $e) {
                        // Keep original value if not a valid date
                    }
                }

                // Get font properties and map to TCPDF font
                $requestedFont = $var['font_family'] ?: 'Arial';
                $tcpdfFont = isset($fontMap[$requestedFont]) ? $fontMap[$requestedFont] : 'helvetica';
                $fontSize = $var['font_size'] ?: 12;
                $color = $var['color'] ?: '#000000';
                $align = strtoupper($var['align']) ?: 'L';

                // Convert hex color to RGB
                list($r, $g, $b) = $hex2rgb($color);

                // Calculate position based on image scaling
                // Since the image is scaled to full page, we need to scale coordinates too
                $scaleX = $pageWidth / $imgWidth;
                $scaleY = $pageHeight / $imgHeight;

                $textX = $var['x'] * $scaleX;
                $textY = $var['y'] * $scaleY;

                // Set font properties
                $pdf->SetFont($tcpdfFont, '', $fontSize);
                $pdf->SetTextColor($r, $g, $b);

                // Set alignment for TCPDF
                $tcpdfAlign = 'L';
                switch ($align) {
                    case 'C':
                        $tcpdfAlign = 'C';
                        break;
                    case 'R':
                        $tcpdfAlign = 'R';
                        break;
                    case 'L':
                    default:
                        $tcpdfAlign = 'L';
                        break;
                }

                // Write text
                $pdf->SetXY($textX, $textY);
                $pdf->Cell(0, 0, $value, 0, 0, $tcpdfAlign);
            }

            // Add certificate metadata
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(100, 100, 100);

            // Certificate number at bottom left
            if (!empty($data['certificate_number'])) {
                $pdf->SetXY(10, $pageHeight - 15);
                $pdf->Cell(0, 0, 'Certificate No: ' . $data['certificate_number'], 0, 0, 'L');
            }

            // Issue date at bottom right
            if (!empty($data['issue_date'])) {
                $margin = 10; // 10mm margin
                $lineHeight = 8;
                $text = 'Issued: ' . $data['issue_date'];
                $textWidth = $pdf->GetStringWidth($text);
                $pdf->SetXY($pageWidth - $margin - $textWidth, $pageHeight - $margin);
                $pdf->Cell(0, 0, $text, 0, 0, 'L');
            }


            // Add QR code placeholder if verifiable
            if ($certificate['is_verifiable'] && !empty($data['certificate_number'])) {
                $pdf->SetFont('helvetica', '', 6);
                $pdf->SetXY($pageWidth - 50, 20);
                $pdf->Cell(40, 40, 'Scan to Verify', 1, 0, 'C');

                $pdf->SetXY($pageWidth - 50, 60);
                $pdf->MultiCell(40, 3, substr($data['certificate_number'], 0, 20), 0, 'C');
            }

            // Output to file
            $pdf->Output($filePath, 'F');

            // Verify file was created
            if (!file_exists($filePath)) {
                throw new \Exception('Failed to create PDF file at: ' . $filePath);
            }

            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize === 0) {
                throw new \Exception('Generated PDF file is empty');
            }

            log_message('debug', 'PDF generated successfully: ' . $filePath . ' (' . $fileSize . ' bytes)');
            return true;

        } catch (\Exception $e) {
            log_message('error', 'TCPDF Generation Error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    // private function generateCertificateWithTCPDF(
    //     string $filePath,
    //     array $certificate,
    //     array $templateVars,
    //     array $data,
    //     string $paperSize = 'A4',
    //     string $quality = 'high',
    //     string $orientation = 'L'
    // ): bool {
    //     try {

    //         /* -------------------------------------------------
    //          * Load TCPDF
    //          * ------------------------------------------------- */
    //         if (!class_exists('TCPDF')) {
    //             $tcpdfPath = ROOTPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php';
    //             if (!file_exists($tcpdfPath)) {
    //                 throw new \Exception('TCPDF not installed');
    //             }
    //             require_once $tcpdfPath;
    //         }

    //         /* -------------------------------------------------
    //          * Resolve template image (URL or local)
    //          * ------------------------------------------------- */
    //         if (empty($certificate['template_path'])) {
    //             throw new \Exception('Certificate template not defined');
    //         }

    //         $templatePath = $certificate['template_path'];

    //         if (filter_var($templatePath, FILTER_VALIDATE_URL)) {
    //             $templateImage = $templatePath; // S3 / remote
    //         } else {
    //             $templateImage = WRITEPATH . str_replace('writable/', '', $templatePath);
    //             if (!file_exists($templateImage)) {
    //                 throw new \Exception('Template image not found: ' . $templateImage);
    //             }
    //         }

    //         $imageInfo = @getimagesize($templateImage);
    //         if (!$imageInfo) {
    //             throw new \Exception('Invalid template image');
    //         }

    //         /* -------------------------------------------------
    //          * Image sizing
    //          * ------------------------------------------------- */
    //         [$imgWidth, $imgHeight, $imageType] = $imageInfo;

    //         $maxPixels = 2000;
    //         if ($imgWidth > $maxPixels || $imgHeight > $maxPixels) {
    //             $scale = $maxPixels / max($imgWidth, $imgHeight);
    //             $imgWidth = (int) ($imgWidth * $scale);
    //             $imgHeight = (int) ($imgHeight * $scale);
    //         }

    //         $dpi = 96;
    //         $pageWidthMm = $imgWidth * (25.4 / $dpi);
    //         $pageHeightMm = $imgHeight * (25.4 / $dpi);

    //         log_message('debug', "Scaled image: {$imgWidth}x{$imgHeight}px");
    //         log_message('debug', "PDF page size: {$pageWidthMm}x{$pageHeightMm} mm");

    //         /* -------------------------------------------------
    //          * TCPDF setup
    //          * ------------------------------------------------- */
    //         $pdf = new \TCPDF($orientation, 'mm', [$pageWidthMm, $pageHeightMm], true, 'UTF-8', false);
    //         $pdf->setPrintHeader(false);
    //         $pdf->setPrintFooter(false);
    //         $pdf->SetMargins(0, 0, 0);
    //         $pdf->SetAutoPageBreak(false, 0);
    //         $pdf->AddPage();

    //         $pageWidth = $pdf->getPageWidth();
    //         $pageHeight = $pdf->getPageHeight();

    //         /* -------------------------------------------------
    //          * Background image
    //          * ------------------------------------------------- */
    //         $pdf->Image($templateImage, 0, 0, $pageWidth, $pageHeight);

    //         /* -------------------------------------------------
    //          * Fonts & colors
    //          * ------------------------------------------------- */
    //         $fontMap = [
    //             'Arial' => 'helvetica',
    //             'Helvetica' => 'helvetica',
    //             'Times' => 'times',
    //             'Times New Roman' => 'times',
    //             'Courier' => 'courier',
    //         ];

    //         $hex2rgb = fn($hex) => sscanf(ltrim($hex, '#'), "%02x%02x%02x");

    //         /* -------------------------------------------------
    //          * Print variables
    //          * ------------------------------------------------- */
    //         $scaleX = $pageWidth / $imgWidth;
    //         $scaleY = $pageHeight / $imgHeight;

    //         foreach ($templateVars as $var) {
    //             $key = $var['var_key'];
    //             if (empty($data[$key])) {
    //                 continue;
    //             }

    //             $value = (string) $data[$key];

    //             if (str_contains($key, 'date')) {
    //                 try {
    //                     $value = (new \DateTime($value))->format('F j, Y');
    //                 } catch (\Exception $e) {
    //                 }
    //             }

    //             $font = $fontMap[$var['font_family'] ?? 'Arial'] ?? 'helvetica';
    //             [$r, $g, $b] = $hex2rgb($var['color'] ?? '#000000');

    //             $pdf->SetFont($font, '', $var['font_size'] ?? 12);
    //             $pdf->SetTextColor($r, $g, $b);

    //             $pdf->SetXY($var['x'] * $scaleX, $var['y'] * $scaleY);
    //             $pdf->Cell(0, 0, $value, 0, 0, strtoupper($var['align'] ?? 'L'));
    //         }

    //         /* -------------------------------------------------
    //          * Build ABSOLUTE output path (CRITICAL FIX)
    //          * ------------------------------------------------- */
    //         $absolutePath = str_starts_with($filePath, WRITEPATH)
    //             ? $filePath
    //             : WRITEPATH . ltrim($filePath, '/');

    //         $dir = dirname($absolutePath);
    //         if (!is_dir($dir)) {
    //             mkdir($dir, 0775, true);
    //         }

    //         /* -------------------------------------------------
    //          * Save PDF
    //          * ------------------------------------------------- */
    //         $pdf->Output($absolutePath, 'F');

    //         if (!file_exists($absolutePath) || filesize($absolutePath) === 0) {
    //             throw new \Exception('PDF generation failed');
    //         }

    //         log_message('debug', 'PDF generated: ' . $absolutePath);
    //         return true;

    //     } catch (\Exception $e) {
    //         log_message('error', 'TCPDF Error: ' . $e->getMessage());
    //         log_message('error', $e->getTraceAsString());
    //         return false;
    //     }
    // }




    /**
     * Create ZIP archive of generated certificates
     */
    private function createZipArchive($files, $certificateId, $targetDir)
    {
        $zipFileName = 'certificates_' . $certificateId . '_' . time() . '.zip';
        $zipFilePath = $targetDir . $zipFileName;

        $zip = new \ZipArchive();

        if ($zip->open($zipFilePath, \ZipArchive::CREATE) === TRUE) {
            foreach ($files as $file) {
                $fullPath = WRITEPATH . str_replace('writable/', '', $file['file_path']);
                if (file_exists($fullPath)) {
                    $zip->addFile($fullPath, basename($file['file_path']));
                }
            }
            $zip->close();

            return 'writable/uploads/certificates/data/' . $certificateId . '/generated/' . $zipFileName;
        }

        return null;
    }

    /**
     * GET /api/certificate/download/all/{certificate_id}
     * Download all certificates as ZIP (already created during generation)
     */
    /**
     * GET /api/certificate/download/all/{certificate_id}
     * Download all certificates as ZIP
     */
    public function downloadAllCertificates($certificateId)
    {
        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Certificate ID required'], 422);
        }

        // Check access
        if (!$this->checkCertificateExistsAndAccess($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Access denied'], 403);
        }

        // Get certificate info
        $certificate = $this->certificateModel->find((int) $certificateId);
        if (!$certificate) {
            return $this->respond(['success' => 0, 'message' => 'Certificate not found'], 404);
        }

        // Check if ZIP exists in database
        if (empty($certificate['last_generated_url'])) {
            return $this->respond(['success' => 0, 'message' => 'No ZIP file available'], 404);
        }

        // Get ZIP file path
        $zipRelativePath = $certificate['last_generated_url'];
        $zipPath = WRITEPATH . str_replace('writable/', '', $zipRelativePath);

        // Check if file exists
        if (!file_exists($zipPath)) {
            return $this->respond(['success' => 0, 'message' => 'ZIP file not found on server'], 404);
        }

        // Generate download filename
        $certificateName = preg_replace('/[^a-z0-9]/i', '_', strtolower($certificate['name']));
        $fileName = $certificateName . '_certificates.zip';

        // DISABLE CodeIgniter's output processing
        $this->response->setHeader('Content-Type', 'application/zip');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $this->response->setHeader('Content-Length', filesize($zipPath));
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Expires', '0');

        // Send headers
        $this->response->sendHeaders();

        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Read and output file
        readfile($zipPath);
        exit;
    }
    /**
     * Recreate ZIP file if missing and download
     */
    private function recreateAndDownloadZip($certificateId)
    {
        // Get certificate info
        $certificate = $this->certificateModel->find($certificateId);
        if (!$certificate) {
            return $this->respond(['success' => 0, 'message' => 'Certificate not found'], 404);
        }

        // Check if we have a last_generated_url that might be a ZIP
        if (!empty($certificate['last_generated_url'])) {
            $lastGeneratedPath = $certificate['last_generated_url'];

            // Check if this is a ZIP file
            if (file_exists($lastGeneratedPath) && pathinfo($lastGeneratedPath, PATHINFO_EXTENSION) === 'zip') {
                // Use the existing ZIP from last_generated_url
                $certificateName = preg_replace('/[^a-z0-9]/i', '_', strtolower($certificate['name']));
                $fileName = $certificateName . '_certificates.zip';

                return $this->response->download($lastGeneratedPath, $fileName);
            }
        }

        // If no ZIP in last_generated_url, check for individual certificate files
        $generatedCertificates = $this->generatedModel->where('certificate_id', $certificateId)->findAll();

        if (empty($generatedCertificates)) {
            return $this->respond(['success' => 0, 'message' => 'No certificates found'], 404);
        }

        // Check if any certificate files exist
        $hasFiles = false;
        foreach ($generatedCertificates as $cert) {
            $filePath = WRITEPATH . str_replace('writable/', '', $cert['file_path']);
            if (file_exists($filePath)) {
                $hasFiles = true;
                break;
            }
        }

        if (!$hasFiles) {
            return $this->respond(['success' => 0, 'message' => 'No certificate files found'], 404);
        }

        // Ask user to regenerate certificates first
        return $this->respond([
            'success' => 0,
            'message' => 'ZIP file not found. Please regenerate certificates to create a new ZIP.',
            'regenerate_url' => base_url('api/certificate/generate'),
            'data' => [
                'certificate_id' => $certificateId,
                'has_individual_files' => $hasFiles,
                'individual_files_count' => count($generatedCertificates)
            ]
        ], 404);
    }


    /**
     * GET /api/certificate/stream/{generated_id}
     * Stream PDF directly to browser
     */
    public function streamGeneratedCertificate($generatedId)
    {
        if (!$generatedId || !is_numeric($generatedId)) {
            return $this->respond(['success' => 0, 'message' => 'Generated certificate ID required'], 422);
        }

        $generated = $this->generatedModel->find((int) $generatedId);
        if (!$generated) {
            return $this->respond(['success' => 0, 'message' => 'Certificate not found'], 404);
        }

        // Check access
        if (!$this->ownsCertificate($generated['certificate_id'])) {
            return $this->respond(['success' => 0, 'message' => 'Access denied'], 403);
        }

        $filePath = WRITEPATH . str_replace('writable/', '', $generated['file_path']);

        if (!file_exists($filePath)) {
            return $this->respond(['success' => 0, 'message' => 'Certificate file not found'], 404);
        }

        // Stream PDF
        $this->response->setHeader('Content-Type', 'application/pdf');
        $this->response->setHeader('Content-Disposition', 'inline; filename="certificate.pdf"');
        $this->response->setBody(file_get_contents($filePath));

        return $this->response;
    }



    /**
     * POST /api/certificate/generate/send
     * Payload: { generated_id, to_email }
     */
    public function generateSend()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $generatedId = $data['generated_id'] ?? null;
        $toEmail = $data['to_email'] ?? null;

        if (!$generatedId || !is_numeric($generatedId) || !$toEmail) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'generated_id and to_email required'], 422);
        }

        $gen = $this->generatedModel->find((int) $generatedId);
        if (!$gen)
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Generated certificate not found'], 404);

        // check certificate belongs to this client OR if not authenticated, deny
        if (!$this->checkCertificateExistsAndAccess((int) $gen['certificate_id'])) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
        }

        $fullPath = WRITEPATH . str_replace('writable/', '', $gen['file_path']);

        $emailService = Services::email();
        try {
            $emailService->setFrom('no-reply@example.com', 'Certificates');
            $emailService->setTo($toEmail);
            $emailService->setSubject('Your Certificate');
            $emailService->setMessage('Please find attached certificate.');

            if (is_file($fullPath)) {
                $emailService->attach($fullPath);
            }

            $sent = $emailService->send(false);
            if ($sent) {
                return $this->respond(['success' => 1, 'status' => true, 'message' => 'Email sent']);
            } else {
                return $this->respond(['success' => 0, 'status' => false, 'message' => 'Failed to send email', 'error' => $emailService->printDebugger(['headers'])], 500);
            }
        } catch (\Throwable $e) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Email error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/certificate/delete
     * Payload: { generated_id } or { certificate_id } to delete last generated
     */
    public function delete()
    {
        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        $generatedId = $data['generated_id'] ?? null;
        $certificateId = $data['certificate_id'] ?? null;

        if ($generatedId) {
            $gen = $this->generatedModel->find((int) $generatedId);
            if (!$gen)
                return $this->respond(['success' => 0, 'status' => false, 'message' => 'Generated certificate not found'], 404);
            if (!$this->ownsCertificate($gen['certificate_id'])) {
                return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
            }
            $fullPath = WRITEPATH . str_replace('writable/', '', $gen['file_path']);
            if (is_file($fullPath))
                @unlink($fullPath);
            $this->generatedModel->deleteById((int) $generatedId);
            return $this->respond(['success' => 1, 'status' => true, 'message' => 'Generated certificate deleted']);
        }

        if ($certificateId && is_numeric($certificateId)) {
            if (!$this->ownsCertificate((int) $certificateId)) {
                return $this->respond(['success' => 0, 'status' => false, 'message' => 'Access denied'], 403);
            }
            $last = $this->generatedModel->findLastByCertificate((int) $certificateId);
            if (!$last)
                return $this->respond(['success' => 0, 'status' => false, 'message' => 'No generated certificate found'], 404);
            $fullPath = WRITEPATH . str_replace('writable/', '', $last['file_path']);
            if (is_file($fullPath))
                @unlink($fullPath);
            $this->generatedModel->deleteById((int) $last['id']);
            return $this->respond(['success' => 1, 'status' => true, 'message' => 'Last generated certificate deleted']);
        }

        return $this->respond(['success' => 0, 'status' => false, 'message' => 'generated_id or certificate_id required'], 422);
    }

    /**
     * GET or POST /api/certificate/verify/{generated_id}
     * Public verification if certificate.is_verifiable = 1
     */
    public function verify($generatedId = null)
    {
        if (!$generatedId) {
            $data = $this->request->getJSON(true) ?? $this->request->getPost();
            $generatedId = $data['id'] ?? null;
        }

        if (!$generatedId || !is_numeric($generatedId)) {
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'generated id required'], 422);
        }

        $gen = $this->generatedModel->find((int) $generatedId);
        if (!$gen)
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Certificate not found'], 404);

        $cert = $this->certificateModel->find((int) $gen['certificate_id']);
        if (!$cert)
            return $this->respond(['success' => 0, 'status' => false, 'message' => 'Parent certificate not found'], 404);

        // If certificate not marked verifiable and requester is not owner, deny
        if (empty($cert['is_verifiable'])) {
            if (!$this->ownsCertificate($cert['id'])) {
                return $this->respond(['success' => 0, 'status' => false, 'message' => 'Verification not allowed for this certificate'], 403);
            }
        }

        return $this->respond([
            'success' => 1,
            'status' => true,
            'message' => 'Certificate valid',
            'certificate' => [
                'generated_id' => (int) $gen['id'],
                'certificate_id' => (int) $gen['certificate_id'],
                'file_path' => $gen['file_path'],
                'data_snapshot' => json_decode($gen['data_snapshot'] ?? $gen['data'] ?? 'null', true),
                'created_at' => $gen['created_at']
            ]
        ]);
    }

    /* -------------------------
     * Helpers
     * ------------------------*/

    protected function ensureCertificateDir(string $certificateId): string
    {
        $path = $this->uploadPath . $certificateId . DIRECTORY_SEPARATOR;
        if (!is_dir($path))
            mkdir($path, 0755, true);
        return $path;
    }

    protected function getClientId(): int
    {
        $client = AuthContext::client();
        return (int) $client['id'];
    }

    protected function ownsCertificate(int $certificateId): bool
    {
        $clientId = AuthContext::clientId();
        if ($clientId <= 0)
            return false;
        $row = $this->certificateModel->findForClient((int) $certificateId, $clientId);
        return (bool) $row;
    }

    protected function checkCertificateExistsAndAccess(int $certificateId): bool
    {
        // allow if owner (client) or if certificate is_verifiable (public generation may be restricted though)
        $cert = $this->certificateModel->find((int) $certificateId);
        if (!$cert)
            return false;
        // $clientId = $this->getClientId();
        $clientId = AuthContext::clientId();
        if ($clientId && ((int) $cert['client_id'] === $clientId))
            return true;
        // if cert is verifiable (public), allow generation only if you want — here we restrict generate to owners only
        return false;
    }

    private function getCertificateSlug($certificate)
    {
        $name = $certificate['name'] ?? 'cert';
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug . '_' . $certificate['id'];
    }

    private function cleanupOldBatches($certificateId, $slug)
    {
        $s3 = new S3Service();
        $parentPrefix = "certificates/data/generated/{$slug}/";
        $batches = $s3->listPrefixes($parentPrefix);

        if (count($batches) > 3) {
            // Sort by name (which includes timestamp batch_YYYYMMDD_HHMMSS)
            sort($batches);

            // Keep only the last 3
            $toDelete = array_slice($batches, 0, count($batches) - 3);

            foreach ($toDelete as $prefix) {
                $s3->deletePrefix($prefix);
                log_message('info', "Cleaned up old batch on S3: {$prefix}");
            }
        }
    }
}
