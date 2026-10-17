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
use App\Libraries\Db;
use App\Libraries\S3Service;
use App\Libraries\AuthContext;
use App\Libraries\CertificatePDFService;

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

        $insert += $this->pageFrom($data);

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
     * POST /api/certificate/page — how big the finished thing is.
     *
     * Separate from creating it because the size is usually decided later,
     * once there is artwork to look at, and because an ID card that came out
     * the size of a certificate is worth being able to correct without making
     * everything again.
     */
    public function page($certificateId = null)
    {
        $data          = $this->request->getJSON(true) ?? $this->request->getPost();
        $certificateId = $certificateId ?? ($data['certificate_id'] ?? null);

        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'certificate_id required'], 422);
        }

        if (!$this->checkCertificateExistsAndAccess($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Access denied'], 403);
        }

        $page = $this->pageFrom($data);

        if ($page === []) {
            return $this->respond([
                'success' => 0,
                'message' => 'Give a width and a height in millimetres, or null for both to size it from the artwork.',
            ], 422);
        }

        if (!empty($data['orientation'])) {
            $page['orientation'] = strtoupper((string) $data['orientation']) === 'P' ? 'P' : 'L';
        }

        $this->certificateModel->update((int) $certificateId, $page);

        return $this->respond([
            'success' => 1,
            'status'  => true,
            'message' => 'Page size saved',
            'page'    => $page,
        ]);
    }

    /**
     * A page size out of a request, if there is one in there.
     *
     * Both dimensions or neither: half a size is not a size, and silently
     * keeping the other half would print something nobody asked for. Sending
     * them empty is how you go back to taking the size from the artwork.
     *
     * @return array<string, float|null>
     */
    private function pageFrom(array $data): array
    {
        $hasKeys = array_key_exists('page_width_mm', $data) || array_key_exists('page_height_mm', $data);

        if (!$hasKeys) {
            return [];
        }

        $width  = (float) ($data['page_width_mm'] ?? 0);
        $height = (float) ($data['page_height_mm'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return ['page_width_mm' => null, 'page_height_mm' => null];
        }

        return [
            'page_width_mm'  => round($width, 2),
            'page_height_mm' => round($height, 2),
        ];
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
 log_message('error', 'CONTENT_TYPE: ' . ($_SERVER['CONTENT_TYPE'] ?? 'NULL'));

    log_message(
        'error',
        'POST: ' . print_r($_POST, true)
    );

    log_message(
        'error',
        'GET POST: ' . print_r(
            $this->request->getPost(),
            true
        )
    );

    log_message(
        'error',
        'FILES: ' . print_r(
            $_FILES,
            true
        )
    );

    $post = $this->request->getPost();

    $certificateId = $post['certificate_id'] ?? null;

    log_message(
        'error',
        'certificateId: ' . ($certificateId ?? 'NULL')
    );
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
        $this->certificateModel->setTemplatePath($certificateId, $pathDb['url']);
        $templatePath = $pathDb['url'];
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
            'template_path' => $templatePath,
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

        $rows = [];

        foreach ($validVariables as $v) {
            $rows[] = [
                'var_key' => $v['var_key'],
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

            $savedVariables[] = $v['var_key'];
        }

        // A layout sent whole replaces the layout, which is the only way one
        // field can appear twice — an ID card's reference is printed, turned
        // into a QR square and turned into a barcode, all from the one value.
        if (!empty($data['replace'])) {
            $count = $this->templateVarModel->replaceFor((int) $certificateId, $rows);
        } else {
            $savedVariables = [];

            foreach ($rows as $row) {
                $varKey = $row['var_key'];
                unset($row['var_key']);

                if ($this->templateVarModel->upsertVar((int) $certificateId, $varKey, $row)) {
                    $count++;
                    $savedVariables[] = $varKey;
                }
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
            'variables' => $variables,
            // The space x and y are measured in.
            //
            // Positions are stored as pixels against the prepared template,
            // and "prepared" is the operative word: a large upload is scaled
            // down before the page is built from it, so the original image's
            // size is not the space the renderer works in. An editor that
            // guessed from the artwork it displays would be right only for
            // templates that happened to be small enough.
            //
            // So the size is reported by the same code that renders, and any
            // editor placing fields can convert to it exactly.
            'canvas' => $this->templateCanvas($certificateId),
        ]);
    }

    /**
     * The pixel size the renderer measures variable positions against.
     *
     * Null when there is no artwork yet, or when it cannot be read — in which
     * case there is nothing to place fields on and nothing to convert against.
     *
     * @return array{width: int, height: int}|null
     */
    private function templateCanvas($certificateId): ?array
    {
        $certificate = $this->certificateModel->find($certificateId);

        if (empty($certificate['template_path'])) {
            return null;
        }

        try {
            $prepared = (new \App\Libraries\TemplateImageCache())
                ->get((string) $certificate['template_path']);
        } catch (\Throwable $e) {
            log_message('error', 'Could not measure template for certificate {id}: {message}', [
                'id'      => $certificateId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'width'  => (int) $prepared['width'],
            'height' => (int) $prepared['height'],
        ];
    }

    /**
     * Writes a set of rows for a certificate in one statement.
     *
     * One round trip rather than one per row. The database is not on the same
     * machine, so a two hundred row upload used to be two hundred separate
     * waits on the network to be told the same thing each time.
     *
     * @param list<mixed> $rows
     *
     * @return array{0: int, 1: list<int>, 2: list<array<string, mixed>>}
     */
    private function storeRows(int $certificateId, array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (is_array($row) && !empty($row)) {
                // Internal keys that have no business in the database.
                unset($row['_row_number'], $row['_validation_errors'], $row['_source_row']);
                $clean[] = $row;
            }
        }

        try {
            $inserted = $this->dataModel->insertRows($certificateId, $clean);
        } catch (\Throwable $e) {
            log_message('error', 'Row insert failed: ' . $e->getMessage());

            return [0, [], [['error' => $e->getMessage(), 'rows' => count($clean)]]];
        }

        if ($inserted === 0) {
            return [0, [], []];
        }

        $ids = array_column(
            $this->dataModel
                ->where('certificate_id', $certificateId)
                ->orderBy('id', 'DESC')
                ->findAll($inserted),
            'id'
        );

        return [$inserted, array_map('intval', array_reverse($ids)), []];
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

                // A submission that is the whole list replaces the whole list.
                //
                // Without this, sending the recipients again simply added them
                // again: twenty-one people became two hundred and thirty-one
                // rows, and every generation afterwards rendered, uploaded and
                // stored eleven copies of each certificate. The caller says
                // whether it is sending everything, because only the caller
                // knows — a spreadsheet uploaded a second time may well be a
                // second batch.
                $replaced = !empty($json['replace'])
                    ? $this->dataModel->clearFor((int) $certificateId)
                    : 0;

                [$inserted, $successIds, $failedRows] =
                    $this->storeRows((int) $certificateId, $data);

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
                    'rows_replaced' => $replaced,
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
                // ?replace=1 for a spreadsheet that is the whole list rather
                // than more rows to add to it. Uploading the same sheet twice
                // is otherwise how a recipient ends up with two certificates.
                $replaced = $this->request->getVar('replace')
                    ? $this->dataModel->clearFor((int) $certificateId)
                    : 0;

                [$inserted, $successIds, $failedRows] =
                    $this->storeRows((int) $certificateId, $extractedData);

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
                    'rows_replaced' => $replaced,
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

        // A batch is built locally and uploaded in one go, so it is quick —
        // but a few hundred certificates still take longer than PHP's default.
        @set_time_limit(300);

        $generatedIds = [];
        $generatedFiles = [];
        $validDataRows = []; // To store data for combined PDF
        $localFiles = [];    // Everything written to tmp, removed at the end

        // The template is fetched and prepared once here, then reused for
        // every certificate in the batch and the combined PDF.
        $pdfService = new CertificatePDFService();

        // --- Batch Identification ---
        $batchId = 'batch_' . date('Ymd_His');
        $slug = $this->getCertificateSlug($certificate);
        $folder = "certificates/data/generated/{$slug}/{$batchId}";

        try {
            /* -------------------------------------------------
             * 1. Generate every certificate to a local file
             * ------------------------------------------------- */
            $pending = [];

            foreach ($rows as $i => $rowData) {

                if (empty($rowData['certificate_number'])) {
                    $rowData['certificate_number'] =
                        'CERT-' . date('Ymd') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
                }

                $rowData['issue_date'] = date('F j, Y');
                $rowData['certificate_id'] = $certificateId;

                $fileName = 'certificate_row_' . ($sourceDataIds[$i] ?? 'new') . '_' . uniqid() . '.pdf';
                $tempPdfPath = $tmpDir . $fileName;

                $pdfContent = $pdfService->generate($certificate, $templateVars, $rowData, [
                    'paperSize' => $paperSize,
                    'quality' => $quality
                ]);

                if (!$pdfContent || file_put_contents($tempPdfPath, $pdfContent) === false) {
                    log_message('error', 'PDF generation failed');
                    continue;
                }

                $localFiles[] = $tempPdfPath;

                $pending[] = [
                    'index'    => $i,
                    'row'      => $rowData,
                    'fileName' => $fileName,
                    'path'     => $tempPdfPath,
                    'key'      => "{$folder}/{$fileName}",
                ];
            }

            /* -------------------------------------------------
             * 2. The combined PDF, from the local files
             *
             * This is what "download them all" means now. It used to be a ZIP
             * of every individual PDF, which was the single most expensive
             * thing this endpoint did: a hundred certificates made a 315 MB
             * archive, because each PDF carries its own copy of the template
             * artwork and a ZIP of PDFs compresses to nothing.
             *
             * The combined PDF holds exactly the same pages and embeds the
             * artwork once, so the same batch comes to under two megabytes.
             * It builds in a fraction of the time, uploads in a fraction of
             * the time, and opens in any PDF reader without unpacking.
             * ------------------------------------------------- */
            $uploads = array_map(static fn (array $one) => [
                'path'    => $one['path'],
                'key'     => $one['key'],
                'options' => [
                    'mimeType' => 'application/pdf',
                    'metadata' => [
                        'certificate_id' => (string) $certificateId,
                        'certificate_no' => $one['row']['certificate_number'],
                    ],
                ],
            ], $pending);

            $combinedKey = null;

            if ($pending !== []) {
                $combinedContent = $pdfService->generateBulk(
                    $certificate,
                    $templateVars,
                    array_column($pending, 'row')
                );

                if ($combinedContent) {
                    $compName = 'combined_' . $certificateId . '_' . time() . '.pdf';
                    $compPath = $tmpDir . $compName;

                    if (file_put_contents($compPath, $combinedContent) !== false) {
                        $localFiles[] = $compPath;
                        $combinedKey = "{$folder}/{$compName}";
                        $uploads[] = [
                            'path'    => $compPath,
                            'key'     => $combinedKey,
                            'options' => ['mimeType' => 'application/pdf', 'isPublic' => true],
                        ];
                    }
                }
            }

            /* -------------------------------------------------
             * 3. Upload everything together
             * ------------------------------------------------- */
            $urls = $this->s3Service->uploadMany($uploads);

            /* -------------------------------------------------
             * 4. Record what landed
             *
             * The uploads are done and the files are real. Losing the
             * connection here would leave them in S3 with nothing pointing at
             * them, and report a failure for work that succeeded — so the
             * connection is picked back up before anything is written.
             * ------------------------------------------------- */
            Db::revive();

            // Written in two statements rather than two per certificate.
            //
            // This used to be an insert and an update for every row in the
            // batch. The database is not on this machine, so each of those is
            // a round trip of its own — a hundred certificates meant two
            // hundred of them, several seconds spent waiting on the network to
            // say the same thing over and over.
            $rows        = [];
            $dataUpdates = [];

            foreach ($pending as $one) {
                $s3Url = $urls[$one['key']] ?? null;

                if ($s3Url === null) {
                    continue;
                }

                $rows[] = [
                    'certificate_id'      => (int) $certificateId,
                    'certificate_data_id' => $sourceDataIds[$one['index']] ?? null,
                    'batch_id'            => $batchId,
                    'file_path'           => $one['key'],
                    'public_url'          => $s3Url,
                    'data_snapshot'       => json_encode($one['row']),
                    'created_at'          => date('Y-m-d H:i:s'),
                ];

                if (!empty($sourceDataIds[$one['index']])) {
                    $dataUpdates[] = [
                        'id'           => $sourceDataIds[$one['index']],
                        'file_path'    => $one['key'],
                        'download_url' => $s3Url,
                    ];
                }

                $validDataRows[] = $one['row'];
            }

            if ($rows !== []) {
                $this->generatedModel->insertBatch($rows);

                // Read back rather than assuming the ids run consecutively from
                // the first: true for this table today, but it is an engine
                // detail and not worth betting the response on.
                $saved = $this->generatedModel
                    ->where('certificate_id', (int) $certificateId)
                    ->where('batch_id', $batchId)
                    ->orderBy('id', 'ASC')
                    ->findAll();

                foreach ($saved as $row) {
                    $generatedIds[]   = (int) $row['id'];
                    $generatedFiles[] = [
                        'id'           => (int) $row['id'],
                        'file_key'     => $row['file_path'],
                        'file_url'     => $row['public_url'],
                        'download_url' => $this->s3Service->presignedUrl($row['file_path']),
                    ];
                }
            }

            if ($dataUpdates !== []) {
                try {
                    $this->dataModel->updateBatch($dataUpdates, 'id');
                } catch (\Throwable $e) {
                    // The certificates are made and recorded; this only writes
                    // a convenience link onto the source row.
                    log_message('error', 'Failed to update certificate_data rows: ' . $e->getMessage());
                }
            }

            $combinedPdfUrl = $combinedKey !== null ? ($urls[$combinedKey] ?? null) : null;

        } finally {
            foreach ($localFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
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
    /**
     * GET /api/certificate/download/zip/{id} — every certificate in one file.
     *
     * The route keeps its old name so nothing calling it has to change, but
     * what it serves is the combined PDF rather than a ZIP. Building a ZIP of
     * the individual PDFs meant writing, uploading and storing the whole batch
     * a second time — a hundred certificates came to 315 MB — for an archive
     * whose contents are the same pages the combined PDF already holds in
     * under two megabytes. PDFs do not compress, so the ZIP saved nothing and
     * cost everything.
     *
     * It is streamed through here rather than redirected to, because the
     * bucket is private and the credentials for it belong on this side.
     */
    public function downloadAllCertificates($certificateId)
    {
        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Certificate ID required'], 422);
        }

        if (!$this->checkCertificateExistsAndAccess($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Access denied'], 403);
        }

        $certificate = $this->certificateModel->find((int) $certificateId);

        if (!$certificate) {
            return $this->respond(['success' => 0, 'message' => 'Certificate not found'], 404);
        }

        $combined = (string) ($certificate['last_generated_url'] ?? '');

        if ($combined === '') {
            return $this->respond([
                'success' => 0,
                'message' => 'Nothing has been generated for this certificate yet.',
            ], 404);
        }

        $name = preg_replace('/[^a-z0-9]+/i', '_', strtolower((string) $certificate['name']));
        $name = trim($name, '_') ?: 'certificates';

        try {
            $body = $this->s3Service->contents($combined);
        } catch (\Throwable $e) {
            log_message('error', 'Could not read the combined PDF for certificate {id}: {message}', [
                'id'      => $certificateId,
                'message' => $e->getMessage(),
            ]);

            return $this->respond([
                'success' => 0,
                'message' => 'The generated file could not be read. Generate again to rebuild it.',
            ], 404);
        }

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '_certificates.pdf"')
            ->setHeader('Content-Length', (string) strlen($body))
            ->setBody($body);
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
    /**
     * GET /api/certificate/generated/{certificateId}
     *
     *   ?batch_id=batch_20260918_175957   one batch (default: the latest)
     *   ?scope=batches                    every batch, with counts, no files
     *
     * The certificates already generated for a certificate, in the same shape
     * the generate call answers with, plus how many there are.
     *
     * Only the newest three batches are kept on S3 (see cleanupOldBatches); an
     * older batch is still listed, with files_available false, rather than
     * handed out as links that no longer lead anywhere. Download links are
     * signed locally, so reading this makes no request to S3.
     */
    public function generatedList($certificateId = null)
    {
        if (!$certificateId || !is_numeric($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'certificate_id required'], 422);
        }

        $certificateId = (int) $certificateId;

        if (!$this->checkCertificateExistsAndAccess($certificateId)) {
            return $this->respond(['success' => 0, 'message' => 'Access denied'], 403);
        }

        $db = db_connect();

        // Every batch, newest first, with its size.
        $batches = $db->table('generated_certificates')
            ->select('batch_id, COUNT(*) AS count, MIN(created_at) AS generated_at', false)
            ->where('certificate_id', $certificateId)
            ->groupBy('batch_id')
            ->orderBy('generated_at', 'DESC')
            ->get()
            ->getResultArray();

        $total = array_sum(array_map('intval', array_column($batches, 'count')));

        // cleanupOldBatches keeps the newest three batches' files on S3.
        $kept = array_slice(array_column($batches, 'batch_id'), 0, 3);

        $batchList = array_map(static fn (array $batch) => [
            'batch_id'        => $batch['batch_id'],
            'count'           => (int) $batch['count'],
            'generated_at'    => $batch['generated_at'],
            'files_available' => in_array($batch['batch_id'], $kept, true),
        ], $batches);

        if ($this->request->getGet('scope') === 'batches') {
            return $this->respond([
                'success' => 1,
                'message' => $total . ' certificate(s) generated across ' . count($batches) . ' batch(es)',
                'data'    => [
                    'certificate_id'  => $certificateId,
                    'total_generated' => $total,
                    'batch_count'     => count($batches),
                    'batches'         => $batchList,
                ],
            ]);
        }

        $batchId = trim((string) $this->request->getGet('batch_id'));
        $batchId = $batchId !== '' ? $batchId : ($batches[0]['batch_id'] ?? null);

        $current = current(array_filter($batchList, static fn ($b) => $b['batch_id'] === $batchId)) ?: null;

        if ($batchId === null || $current === null) {
            return $this->respond([
                'success' => $batches === [] ? 1 : 0,
                'message' => $batches === [] ? 'No certificates generated yet' : 'That batch was not found',
                'data'    => [
                    'certificate_id'  => $certificateId,
                    'total_generated' => $total,
                    'count'           => 0,
                    'batches'         => $batchList,
                    'files'           => [],
                ],
            ], $batches === [] ? 200 : 404);
        }

        $rows = $this->generatedModel
            ->where('certificate_id', $certificateId)
            ->where('batch_id', $batchId)
            ->orderBy('id', 'ASC')
            ->findAll();

        $available = $current['files_available'];

        $files = array_map(function (array $row) use ($available) {
            $snapshot = json_decode((string) ($row['data_snapshot'] ?? ''), true) ?: [];

            return [
                'id'                  => (int) $row['id'],
                'certificate_data_id' => $row['certificate_data_id'] !== null ? (int) $row['certificate_data_id'] : null,
                'certificate_number'  => $snapshot['certificate_number'] ?? null,
                'file_key'            => $row['file_path'],
                'file_url'            => $available ? $row['public_url'] : null,
                'download_url'        => $available ? $this->signedOrNull($row['file_path']) : null,
                'created_at'          => $row['created_at'],
            ];
        }, $rows);

        // The combined PDF is recorded only for the latest generation.
        $certificate = $this->certificateModel->find($certificateId);
        $combined    = (string) ($certificate['last_generated_url'] ?? '');
        $combinedUrl = $available && $combined !== '' && str_contains($combined, '/' . $batchId . '/') ? $combined : null;

        return $this->respond([
            'success' => 1,
            'message' => count($files) . ' certificate(s) in this batch, ' . $total . ' generated in total',
            'data'    => [
                'certificate_id'   => $certificateId,
                'batch_id'         => $batchId,
                'count'            => count($files),
                'total_generated'  => $total,
                'files_available'  => $available,
                'generated_ids'    => array_column($files, 'id'),
                'combined_pdf_url' => $combinedUrl,
                'batches'          => $batchList,
                'files'            => $files,
            ],
        ]);
    }

    /** A signed download link, or null if S3 is not available. */
    private function signedOrNull(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        try {
            return $this->s3Service->presignedUrl($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

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
