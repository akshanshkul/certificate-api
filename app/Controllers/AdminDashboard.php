<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\CertificateModel;
use App\Models\GeneratedCertificateModel;
use App\Models\ClientApiKeyModel;
use App\Models\UserModel;
use App\Libraries\S3Service;
use App\Libraries\CertificatePDFService;

class AdminDashboard extends BaseController
{
    protected $clientModel;
    protected $certificateModel;
    protected $generatedModel;
    protected $userModel;
    protected $clientApiKeyModel;
    protected $session;

    public function __construct()
    {
        $this->clientModel = new ClientModel();
        $this->certificateModel = new CertificateModel();
        $this->generatedModel = new GeneratedCertificateModel();
        $this->userModel = new UserModel();
        $this->clientApiKeyModel = new ClientApiKeyModel();
        $this->session = \Config\Services::session();
    }

    public function login()
    {
        if ($this->session->get('is_admin')) {
            return redirect()->to('/admin/dashboard');
        }
        return view('admin/login');
    }

    public function authenticate()
    {
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        $user = $this->userModel->findByEmail($email);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Check if admin (you might want to enforce user_type = 'admin')
            $this->session->set([
                'user_id' => $user['id'],
                'user_name' => $user['name'],
                'is_admin' => true,
                'logged_in' => true
            ]);
            return redirect()->to('/admin/dashboard');
        }

        return redirect()->back()->with('error', 'Invalid credentials');
    }

    public function logout()
    {
        $this->session->destroy();
        return redirect()->to('/admin/login');
    }

    public function dashboard()
    {
        if (!$this->session->get('is_admin')) {
            return redirect()->to('/admin/login');
        }

        $data = [
            'title' => 'Admin Dashboard',
            'stats' => [
                'total_clients' => $this->clientModel->countAllResults(),
                'total_certificates' => $this->certificateModel->countAllResults(),
                'total_generated' => $this->generatedModel->countAllResults(),
            ],
            'recent_generations' => $this->generatedModel
                ->select('generated_certificates.*, certificates.name as cert_name')
                ->join('certificates', 'certificates.id = generated_certificates.certificate_id')
                ->orderBy('generated_certificates.id', 'DESC')
                ->limit(10)
                ->findAll()
        ];

        return view('admin/dashboard', $data);
    }

    public function clients()
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $data = [
            'title' => 'Clients Management',
            'clients' => $this->clientModel->findAll()
        ];
        return view('admin/clients/index', $data);
    }

    public function newClient()
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');
        return view('admin/clients/create', ['title' => 'Create New Client']);
    }

    public function saveClient()
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $data = $this->request->getPost();
        if (empty($data['name'])) {
            return redirect()->back()->withInput()->with('error', 'Client Name is required');
        }

        $id = $this->clientModel->insert([
            'name' => $data['name'],
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'plan' => $data['plan'] ?? 'free',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($id) {
            return redirect()->to('/admin/clients')->with('success', 'Client created successfully');
        }

        return redirect()->back()->withInput()->with('error', 'Failed to create client');
    }

    public function clientView($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $client = $this->clientModel->find($id);
        if (!$client)
            return redirect()->to('/admin/clients')->with('error', 'Client not found');

        $data = [
            'title' => 'Client Details: ' . $client['name'],
            'client' => $client,
            'certificates' => $this->certificateModel->where('client_id', $id)->findAll(),
            'api_keys' => $this->clientApiKeyModel->where('client_id', $id)->findAll()
        ];
        return view('admin/clients/view', $data);
    }

    public function generateKey($clientId)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $apiKey = 'cli_' . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        $secretHash = hash('sha256', $secret);

        $this->clientApiKeyModel->insert([
            'client_id' => $clientId,
            'label' => $this->request->getPost('label') ?? 'primary-key',
            'api_key' => $apiKey,
            'secret_hash' => $secretHash,
            'revoked' => 0,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => 'admin'
        ]);

        return redirect()->back()->with('success', 'API Key generated! Key: ' . $apiKey . ' | Secret: ' . $secret . ' (SAVE THIS SECRET NOW, IT WILL NOT BE SHOWN AGAIN)');
    }

    public function revokeKey($apiKey)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $this->clientApiKeyModel->where('api_key', $apiKey)->set(['revoked' => 1])->update();
        return redirect()->back()->with('success', 'API Key revoked successfully');
    }

    public function certificates()
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $data = [
            'title' => 'Certificates Explorer',
            'certificates' => $this->certificateModel
                ->select('certificates.*, clients.name as client_name')
                ->join('clients', 'clients.id = certificates.client_id')
                ->findAll()
        ];
        return view('admin/certificates/index', $data);
    }

    public function certificateView($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        if (!$cert)
            return redirect()->to('/admin/certificates')->with('error', 'Certificate not found');

        $data = [
            'title' => 'Certificate Details: ' . $cert['name'],
            'certificate' => $cert,
            'generations' => $this->generatedModel->where('certificate_id', $id)->orderBy('id', 'DESC')->limit(20)->findAll()
        ];
        return view('admin/certificates/view', $data);
    }

    public function newCertificate($clientId = null)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        return view('admin/certificates/new', [
            'title' => 'Create New Certificate',
            'clients' => $this->clientModel->findAll(),
            'selected_client_id' => $clientId
        ]);
    }

    public function createCertificate()
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $clientId = $this->request->getPost('client_id');
        $name = $this->request->getPost('name');

        $certId = $this->certificateModel->insert([
            'client_id' => $clientId,
            'name' => $name,
            'orientation' => $this->request->getPost('orientation') ?? 'L',
            'paper_size' => $this->request->getPost('paper_size') ?? 'A4',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to('/admin/certificates/' . $certId . '/upload')->with('success', 'Certificate record created! Now upload assets.');
    }

    public function uploadAssetsUI($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        return view('admin/certificates/upload', [
            'title' => 'Upload Assets: ' . $cert['name'],
            'certificate' => $cert
        ]);
    }

    public function saveAssets($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        if (!$cert) {
            return redirect()->to('/admin/certificates')->with('error', 'Certificate not found');
        }

        $file = $this->request->getFile('template_file');
        if ($file && $file->isValid()) {
            $allowedTypes = ['application/pdf', 'image/png', 'image/jpeg'];
            if (!in_array($file->getMimeType(), $allowedTypes)) {
                return redirect()->back()->with('error', 'Only PDF, PNG, and JPG templates are allowed.');
            }

            $s3 = new S3Service();
            $folder = 'certificates/assets/' . $this->getCertificateSlug($cert);
            $pathDb = $s3->uploadDocument($file, $folder, [
                'filename' => 'admin_' . time() . '_' . $file->getName(),
                'mimeType' => $file->getMimeType()
            ]);

            if ($pathDb && !empty($pathDb['url'])) {
                $this->certificateModel->update($id, [
                    'template_path' => $pathDb['url'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }

        // Handle additional logos/assets
        $logoFiles = $this->request->getFileMultiple('logos');
        $savedLogos = [];
        if ($logoFiles) {
            $s3 = new S3Service();
            $folder = 'certificates/assets/' . $this->getCertificateSlug($cert);
            foreach ($logoFiles as $logo) {
                if ($logo->isValid() && !$logo->hasMoved()) {
                    $lName = 'logo_' . $id . '_' . time() . '_' . $logo->getName();
                    $res = $s3->uploadDocument($logo, $folder, [
                        'filename' => $lName,
                        'mimeType' => $logo->getMimeType()
                    ]);
                    if ($res && isset($res['url'])) {
                        $savedLogos[] = $res['url'];
                    }
                }
            }
        }

        // Note: We currently don't have a 'logos' or 'assets' column in 'certificates' table.
        // For now, these are uploaded to S3. We could save them to a JSON column later.
        if (!empty($savedLogos)) {
            log_message('info', 'Admin uploaded logos for cert ' . $id . ': ' . json_encode($savedLogos));
        }

        return redirect()->to('/admin/certificates/' . $id . '/data')->with('success', 'Assets uploaded successfully! Now upload data sheet.');
    }

    public function setupTemplateVariables($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        $templateVarModel = new \App\Models\CertificateTemplateVarModel();

        $dataKeys = [];
        if (!empty($cert['data_keys'])) {
            $dataKeys = json_decode($cert['data_keys'], true) ?: [];
        }

        return view('admin/certificates/setup_variables', [
            'title' => 'Setup Variables: ' . $cert['name'],
            'certificate' => $cert,
            'variables' => $templateVarModel->where('certificate_id', $id)->findAll(),
            'data_keys' => $dataKeys
        ]);
    }

    public function saveTemplateVariables($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $variables = $this->request->getPost('variables');
        $templateVarModel = new \App\Models\CertificateTemplateVarModel();

        // Remove existing vars for this certificate to overwrite
        $templateVarModel->where('certificate_id', $id)->delete();

        if (is_array($variables)) {
            foreach ($variables as $v) {
                if (empty($v['var_key']))
                    continue;
                $templateVarModel->insert([
                    'certificate_id' => $id,
                    'var_key' => $v['var_key'],
                    'x' => (int) $v['x'],
                    'y' => (int) $v['y'],
                    'font_size' => (int) $v['font_size'],
                    'color' => $v['color'] ?? '#000000',
                    'align' => $v['align'] ?? 'L',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Also update data_keys for compatibility
        $keys = is_array($variables) ? array_column($variables, 'var_key') : [];
        $this->certificateModel->update($id, [
            'data_keys' => json_encode($keys),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return redirect()->to('/admin/certificates/' . $id)->with('success', 'Variables saved successfully!');
    }

    public function dataEntryUI($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        return view('admin/certificates/data_entry', [
            'title' => 'Data Entry: ' . $cert['name'],
            'certificate' => $cert
        ]);
    }

    public function validateDataSheet($id)
    {
        if (!$this->session->get('is_admin'))
            return $this->response->setJSON(['error' => 'Unauthorized']);

        $file = $this->request->getFile('data_sheet');
        if (!$file || !$file->isValid() || !in_array($file->getExtension(), ['csv', 'xlsx', 'xls'])) {
            return $this->response->setJSON(['error' => 'Please upload a valid CSV or Excel file.']);
        }

        try {
            list($extractedData, $headers) = $this->extractDataWithPhpSpreadsheet(
                $file->getTempName(),
                $file->getExtension()
            );

            $previewRows = array_slice($extractedData, 0, 5);

            return $this->response->setJSON([
                'success' => true,
                'headers' => $headers ?? [],
                'preview' => $previewRows,
                'total_rows_count' => count($extractedData)
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Data validation error: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'Failed to parse file: ' . $e->getMessage()]);
        }
    }

    public function saveDataSheet($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $file = $this->request->getFile('data_sheet');
        if (!$file || !$file->isValid()) {
            return redirect()->back()->with('error', 'Please upload a data sheet.');
        }

        try {
            list($extractedData, $headers) = $this->extractDataWithPhpSpreadsheet(
                $file->getTempName(),
                $file->getExtension()
            );

            if (empty($extractedData)) {
                return redirect()->back()->with('error', 'No data found in the file.');
            }

            $certificateDataModel = new \App\Models\CertificateDataModel();
            foreach ($extractedData as $row) {
                $certificateDataModel->insertRow($id, $row);
            }

            $this->certificateModel->update($id, [
                'data_keys' => json_encode($headers),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return redirect()->to('/admin/certificates/' . $id . '/setup')->with('success', 'Data saved! Total ' . count($extractedData) . ' rows imported. Now map variables.');

        } catch (\Exception $e) {
            log_message('error', 'Data save error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to process file: ' . $e->getMessage());
        }
    }

    /**
     * Extract data using PhpSpreadsheet (handles all file types)
     */
    private function extractDataWithPhpSpreadsheet($filePath, $extension, $skipRows = 0, $sheetIndex = 0)
    {
        $extractedData = [];
        $headers = [];

        // Load the spreadsheet
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheetNames = $spreadsheet->getSheetNames();

        // Get the specific sheet
        if ($sheetIndex >= 0 && $sheetIndex < count($sheetNames)) {
            $worksheet = $spreadsheet->getSheet($sheetIndex);
        } else {
            $worksheet = $spreadsheet->getActiveSheet();
        }

        // Get highest row and column
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // If skipRows is 0, try to auto-detect header row
        $headerRowIndex = $skipRows > 0 ? $skipRows : 1;

        // Extract headers
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $headerRowIndex;
            $cell = $worksheet->getCell($cellCoordinate);
            $value = $cell->getValue();

            // Clean header name
            $headerName = $this->cleanHeaderName($value, $col);
            $headers[] = $headerName;
        }

        // Extract data rows
        $dataStartRow = $headerRowIndex + 1;

        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $rowData = [];
            $hasData = false;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                $cell = $worksheet->getCell($cellCoordinate);
                $value = $cell->getValue();

                // Handle rich text
                if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $value = $value->getPlainText();
                }

                // Convert date/time values
                if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                    try {
                        $value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                    }
                }

                // Clean the value
                $value = $this->cleanCellValue($value);

                $headerName = $headers[$col - 1] ?? "column_$col";
                $rowData[$headerName] = $value;

                if (!empty(trim($value ?? ''))) {
                    $hasData = true;
                }
            }

            if ($hasData) {
                $extractedData[] = $rowData;
            }
        }

        if (empty(array_filter($headers))) {
            $headers = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $headers[] = "column_$col";
            }
        }

        return [$extractedData, $headers];
    }

    private function cleanHeaderName($name, $colIndex)
    {
        if (empty($name)) {
            return "field_$colIndex";
        }
        $name = preg_replace('/[^\p{L}\p{N}\s_\-]/u', '', $name);
        $name = trim($name);
        $name = preg_replace('/\s+/', '_', $name);
        if (empty($name)) {
            return "field_$colIndex";
        }
        return strtolower($name);
    }

    private function cleanCellValue($value)
    {
        if ($value === null)
            return '';
        $value = (string) $value;
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value;
    }


    public function processBatchGeneration($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        if (!$cert) {
            return redirect()->back()->with('error', 'Certificate not found.');
        }

        // Get template variables
        $templateVarModel = new \App\Models\CertificateTemplateVarModel();
        $vars = $templateVarModel->where('certificate_id', $id)->findAll();

        // Get all data rows
        $dataModel = new \App\Models\CertificateDataModel();
        $allDataRows = $dataModel->where('certificate_id', $id)->findAll();

        if (empty($allDataRows)) {
            return redirect()->back()->with('error', 'No data rows found. Please upload a data sheet first.');
        }

        $s3 = new S3Service();
        $generatedResults = [];
        $validDataRows = []; // Collective data for combined PDF
        $successCount = 0;
        $failCount = 0;

        // --- Batch Identification ---
        $batchId = 'batch_' . date('Ymd_His');
        $slug = $this->getCertificateSlug($cert);
        $folder = "certificates/data/generated/{$slug}/{$batchId}";

        foreach ($allDataRows as $row) {
            try {
                $rowData = json_decode($row['data'], true) ?: [];
                if (empty($rowData))
                    continue;

                // Ensure certificate number or generate one
                if (empty($rowData['certificate_number'])) {
                    $rowData['certificate_number'] = 'CERT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                }

                // Generate PDF (Returned as string/binary)
                $pdfContent = $pdfService->generate($cert, $vars, $rowData);
                if (!$pdfContent) {
                    $failCount++;
                    continue;
                }

                // Upload to S3
                $fileName = 'certificate_row_' . $row['id'] . '_' . uniqid() . '.pdf';

                $uploadRes = $s3->uploadDocument($pdfContent, $folder, [
                    'filename' => $fileName,
                    'mimeType' => 'application/pdf',
                    'isPublic' => true
                ]);

                if ($uploadRes && isset($uploadRes['url'])) {
                    $s3Url = $uploadRes['url'];
                    $actualS3Key = $uploadRes['key'] ?? "{$folder}/{$fileName}";

                    // Save to generated_certificates
                    $genId = $this->generatedModel->createGenerated(
                        (int) $id,
                        $actualS3Key,
                        $rowData,
                        (int) $row['id'],
                        $s3Url,
                        $batchId
                    );

                    // Update source data row
                    $dataModel->update($row['id'], [
                        'file_path' => $actualS3Key,
                        'download_url' => $s3Url,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                    $generatedResults[] = [
                        'id' => $genId,
                        'url' => $s3Url,
                        'name' => $rowData['name'] ?? 'Recipient'
                    ];
                    $successCount++;
                    $validDataRows[] = $rowData; // For combined PDF
                } else {
                    $failCount++;
                }

            } catch (\Exception $e) {
                log_message('error', 'Batch Generation Error for row ' . $row['id'] . ': ' . $e->getMessage());
                $failCount++;
            }
        }

        // --- Generate Combined PDF ---
        $combinedPdfUrl = null;
        if (!empty($validDataRows)) {
            $combinedContent = $pdfService->generateBulk($cert, $vars, $validDataRows);
            if ($combinedContent) {
                $compName = 'combined_' . $id . '_' . time() . '.pdf';
                $compRes = $s3->uploadDocument($combinedContent, $folder, [
                    'filename' => $compName,
                    'mimeType' => 'application/pdf',
                    'isPublic' => true
                ]);
                if ($compRes && isset($compRes['url'])) {
                    $combinedPdfUrl = $compRes['url'];
                }
            }
        }

        // Mark certificate as having generated
        $this->certificateModel->markGenerated($id, $combinedPdfUrl ?: ($generatedResults[0]['url'] ?? null));

        // --- Cleanup Old Batches (Keep Last 3) ---
        $this->cleanupOldBatches($id, $slug);

        $msg = "Batch generation complete! {$successCount} certificates generated successfully.";
        if ($failCount > 0) {
            $msg .= " {$failCount} rows failed.";
        }

        return view('admin/certificates/generation_results', [
            'title' => 'Generation Results: ' . $cert['name'],
            'certificate' => $cert,
            'results' => $generatedResults,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'message' => $msg,
            'combined_pdf_url' => $combinedPdfUrl,
        ]);
    }

    private function getCertificateSlug($certificate)
    {
        $name = $certificate['name'] ?? 'cert';
        // Lowercase, remove special chars, spaces to underscores
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
            // Since they are sorted ascending, the first ones are the oldest
            $toDelete = array_slice($batches, 0, count($batches) - 3);

            foreach ($toDelete as $prefix) {
                $s3->deletePrefix($prefix);
                log_message('info', "Cleaned up old batch on S3: {$prefix}");
            }
        }
    }

    public function listBatches($certificateId)
    {
        $cert = $this->certificateModel->find($certificateId);
        if (!$cert) {
            return redirect()->to('/admin/certificates')->with('error', 'Certificate not found.');
        }

        // Group by batch_id and count
        $batches = $this->generatedModel
            ->select('batch_id, COUNT(*) as count, MAX(created_at) as last_generated')
            ->where('certificate_id', $certificateId)
            ->where('batch_id !=', null)
            ->groupBy('batch_id')
            ->orderBy('last_generated', 'DESC')
            ->findAll();

        return view('admin/certificates/batches', [
            'certificate' => $cert,
            'batches' => $batches,
            'title' => 'Generation Batches - ' . $cert['name']
        ]);
    }

    public function viewBatch($certificateId, $batchId)
    {
        $cert = $this->certificateModel->find($certificateId);
        if (!$cert) {
            return redirect()->to('/admin/certificates')->with('error', 'Certificate not found.');
        }

        $certificates = $this->generatedModel
            ->where('certificate_id', $certificateId)
            ->where('batch_id', $batchId)
            ->findAll();

        return view('admin/certificates/batch_view', [
            'certificate' => $cert,
            'batch_id' => $batchId,
            'certificates' => $certificates,
            'title' => 'Batch Results - ' . $batchId
        ]);
    }

    public function deleteBatch($certId, $batchId)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($certId);
        if (!$cert) {
            return redirect()->back()->with('error', 'Certificate not found.');
        }

        $slug = $this->getCertificateSlug($cert);
        $s3 = new S3Service();

        // 1. Delete generated files from S3 (folder)
        $folder = "certificates/data/generated/{$slug}/{$batchId}";
        $s3->deletePrefix($folder);

        // 2. Delete DB records for this batch
        $this->generatedModel->where('certificate_id', $certId)
            ->where('batch_id', $batchId)
            ->delete();

        return redirect()->to('/admin/certificates/' . $certId . '/batches')->with('success', 'Batch ' . $batchId . ' deleted successfully.');
    }

    public function deleteCertificate($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        if (!$cert) {
            return redirect()->to('/admin/certificates')->with('error', 'Certificate not found.');
        }

        $slug = $this->getCertificateSlug($cert);
        $s3 = new S3Service();

        // 1. Delete all generated files from S3
        $genFolder = "certificates/data/generated/{$slug}";
        $s3->deletePrefix($genFolder);

        // 2. Delete all assets (template, logos) from S3
        $assetsFolder = "certificates/assets/{slug}";
        $s3->deletePrefix($assetsFolder);

        // 3. Delete from related DB tables
        $templateVarModel = new \App\Models\CertificateTemplateVarModel();
        $templateVarModel->where('certificate_id', $id)->delete();

        $dataModel = new \App\Models\CertificateDataModel();
        $dataModel->where('certificate_id', $id)->delete();

        $this->generatedModel->where('certificate_id', $id)->delete();

        // 4. Delete the certificate itself
        $this->certificateModel->delete($id);

        return redirect()->to('/admin/certificates')->with('success', 'Certificate "' . $cert['name'] . '" and all its data have been permanently deleted.');
    }

    public function downloadLatestPDF($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        if (!$cert || empty($cert['last_generated_url'])) {
            return redirect()->back()->with('error', 'No combined PDF available for this certificate yet.');
        }

        return redirect()->to($cert['last_generated_url']);
    }

    public function previewCertificate($id)
    {
        if (!$this->session->get('is_admin'))
            return redirect()->to('/admin/login');

        $cert = $this->certificateModel->find($id);
        if (!$cert) {
            return redirect()->back()->with('error', 'Certificate not found.');
        }

        // Get template variables
        $templateVarModel = new \App\Models\CertificateTemplateVarModel();
        $vars = $templateVarModel->where('certificate_id', $id)->findAll();

        // Get first row of data
        $dataModel = new \App\Models\CertificateDataModel();
        $sampleDataRow = $dataModel->where('certificate_id', $id)->orderBy('id', 'ASC')->first();

        // Fallback data if no data rows uploaded yet
        if (!$sampleDataRow) {
            $sampleDataRow = [];
            if (!empty($cert['data_keys'])) {
                $keys = json_decode($cert['data_keys'], true) ?: [];
                foreach ($keys as $key) {
                    $sampleDataRow[$key] = '[' . strtoupper($key) . ' SAMPLE]';
                }
            }
        } else {
            $sampleDataRow = json_decode($sampleDataRow['data'], true) ?: [];
        }

        if (empty($sampleDataRow)) {
            return redirect()->back()->with('error', 'Please upload a data sheet first to get headers for preview.');
        }

        $pdfService = new CertificatePDFService();
        $pdfContent = $pdfService->generate($cert, $vars, $sampleDataRow);

        if (!$pdfContent) {
            return redirect()->back()->with('error', 'Failed to generate preview. Check if template and variables are set.');
        }

        return $this->response->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="preview_' . $cert['name'] . '.pdf"')
            ->setBody($pdfContent);
    }
}
