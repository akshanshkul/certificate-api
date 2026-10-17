<?php

use CodeIgniter\Router\RouteCollection;
/**
 * @var RouteCollection $routes
 */
$routes->GET('/', 'Home::index');

$routes->POST('api/login', 'Api\Auth::login');

$routes->group('api', ['filter' => 'jwt'], function ($routes) {
    $routes->POST('registerUser', 'Api\Auth::registerUser');
    $routes->POST('logout', 'Api\Auth::logout');
    $routes->POST('clients/create', 'Api\Client::createClient');
    $routes->GET('clients/list', 'Api\Client::listClients');
    $routes->POST('clients/(:num)/keys', 'Api\Client::generateApiKey/$1');
    $routes->GET('clients/(:num)/keys', 'Api\Client::listKeys/$1');
    $routes->POST('clients/keys/revoke', 'Api\Client::revokeApiKey');
    $routes->POST('clients/(:num)/deactivate', 'Api\Client::deactivateClient/$1');
    $routes->POST('clients/(:num)/activate', 'Api\Client::activateClient/$1');
});

$routes->group('api/certificate', ['filter' => 'apiAuth'], function ($routes) {
    $routes->POST('create', 'Api\Certificate::create');
    $routes->POST('template/upload', 'Api\Certificate::templateUpload');
    $routes->POST('template/update', 'Api\Certificate::templateUpdate');
    $routes->GET('template/variables/(:num)', 'Api\Certificate::getVariables/$1');
    $routes->POST('page', 'Api\Certificate::page');
    $routes->POST('page/(:num)', 'Api\Certificate::page/$1');
    $routes->POST('template/upload/data', 'Api\Certificate::templateUploadData');
    $routes->POST('generate', 'Api\Certificate::generate');
    $routes->GET('generated/(:num)', 'Api\Certificate::generatedList/$1');
    $routes->GET('download/all/(:num)', 'Api\Certificate::downloadAllCertificates/$1');
    $routes->GET('download/zip/(:num)', 'Api\Certificate::downloadAllCertificates/$1');

    $routes->POST('generate/send', 'Api\Certificate::generateSend');

    // 7. Delete generated certificate or last generated certificate
});

// Admin Dashboard API
$routes->group('api/admin', ['namespace' => 'App\Controllers\Api', 'filter' => 'jwt'], function ($routes) {
    $routes->get('stats', 'Admin::getStats');
    $routes->group('clients', function ($routes) {
        $routes->get('/', 'Admin::listClients');
        $routes->post('/', 'Admin::createClient');
        $routes->get('(:num)', 'Admin::getClient/$1');
        $routes->put('(:num)', 'Admin::updateClient/$1');
        $routes->delete('(:num)', 'Admin::deleteClient/$1');
    });
    $routes->group('certificates', function ($routes) {
        $routes->get('/', 'Admin::listCertificates');
        $routes->get('(:num)', 'Admin::getCertificateDetails/$1');
    });
});

// Admin Dashboard UI (Web)
$routes->get('admin/login', 'AdminDashboard::login');
$routes->post('admin/login', 'AdminDashboard::authenticate');
$routes->get('admin/logout', 'AdminDashboard::logout');
$routes->get('admin/dashboard', 'AdminDashboard::dashboard');
$routes->get('admin/clients', 'AdminDashboard::clients');
$routes->get('admin/clients/new', 'AdminDashboard::newClient');
$routes->post('admin/clients/save', 'AdminDashboard::saveClient');
$routes->get('admin/clients/(:num)', 'AdminDashboard::clientView/$1');
$routes->post('admin/clients/(:num)/generate-key', 'AdminDashboard::generateKey/$1');
$routes->get('admin/clients/revoke-key/(:any)', 'AdminDashboard::revokeKey/$1');
$routes->get('admin/certificates', 'AdminDashboard::certificates');
$routes->get('admin/certificates/new', 'AdminDashboard::newCertificate');
$routes->get('admin/certificates/new/(:num)', 'AdminDashboard::newCertificate/$1');
$routes->post('admin/certificates/create', 'AdminDashboard::createCertificate');
$routes->get('admin/certificates/(:num)', 'AdminDashboard::certificateView/$1');

$routes->get('admin/certificates/(:num)/upload', 'AdminDashboard::uploadAssetsUI/$1');
$routes->post('admin/certificates/(:num)/upload', 'AdminDashboard::saveAssets/$1');

$routes->get('admin/certificates/(:num)/data', 'AdminDashboard::dataEntryUI/$1');
$routes->post('admin/certificates/(:num)/data', 'AdminDashboard::saveDataSheet/$1');
$routes->post('admin/certificates/(:num)/validate-data', 'AdminDashboard::validateDataSheet/$1');

$routes->get('admin/certificates/(:num)/setup', 'AdminDashboard::setupTemplateVariables/$1');
$routes->post('admin/certificates/(:num)/setup', 'AdminDashboard::saveTemplateVariables/$1');
$routes->get('admin/certificates/(:num)/preview', 'AdminDashboard::previewCertificate/$1');

$routes->post('admin/certificates/(:num)/generate', 'AdminDashboard::processBatchGeneration/$1');
$routes->get('admin/certificates/(:num)/batches', 'AdminDashboard::listBatches/$1');
$routes->get('admin/certificates/(:num)/batches/(:any)', 'AdminDashboard::viewBatch/$1/$2');
$routes->post('admin/certificates/(:num)/delete', 'AdminDashboard::deleteCertificate/$1');
$routes->post('admin/certificates/(:num)/batches/(:any)/delete', 'AdminDashboard::deleteBatch/$1/$2');
$routes->get('admin/certificates/(:num)/download-pdf', 'AdminDashboard::downloadLatestPDF/$1');





// optional: explicit GET ~> method not allowed JSON (avoid 404 for accidental browser GET)
$routes->GET('(:any)', function ($segment) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    return json_encode([
        'success' => 0,
        'status' => false,
        'message' => 'Method Not Allowed. Use POST for API endpoints.'
    ]);
});
