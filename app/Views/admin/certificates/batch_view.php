<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-7">
            <h1 class="h3 mb-0 text-gray-800"><?= esc($title) ?></h1>
            <p class="mb-0">Batch ID: <span class="badge badge-info"><?= esc($batch_id) ?></span></p>
        </div>
        <div class="col-md-5 text-right">
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/batches') ?>"
                class="btn btn-secondary">
                <i class="fas fa-list"></i> All Batches
            </a>
            <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" class="btn btn-primary">
                Certificate Details
            </a>
        </div>
    </div>

    <!-- Bulk Download Section -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Bulk Downloads</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">Available For This Batch</div>

                            <div class="mt-3">
                                <?php if ($certificate['last_generated_url']): ?>
                                    <a href="<?= esc($certificate['last_generated_url']) ?>"
                                        class="btn btn-sm btn-success mr-2" target="_blank">
                                        <i class="fas fa-file-pdf"></i> Combined PDF
                                    </a>
                                <?php endif; ?>

                                <?php if ($zip_job): ?>
                                    <?php if ($zip_job['status'] === 'completed'): ?>
                                        <a href="<?= esc($zip_job['zip_url']) ?>" class="btn btn-sm btn-primary"
                                            target="_blank">
                                            <i class="fas fa-file-archive"></i> Download ZIP
                                        </a>
                                    <?php elseif ($zip_job['status'] === 'failed'): ?>
                                        <span class="badge badge-danger">ZIP Failed</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">ZIP Processing... (<?= $zip_job['status'] ?>)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">No ZIP job recorded for this batch</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-download fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Certificates Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Generated Certificates (<?= count($certificates) ?>)</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Certificate No</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($certificates as $gen): ?>
                            <?php $info = json_decode($gen['data_snapshot'], true); ?>
                            <tr>
                                <td><?= esc($info['name'] ?? 'Recipient') ?></td>
                                <td><?= esc($info['certificate_number'] ?? 'N/A') ?></td>
                                <td>
                                    <a href="<?= esc($gen['public_url']) ?>" class="btn btn-sm btn-info" target="_blank">
                                        <i class="fas fa-external-link-alt"></i> View PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>