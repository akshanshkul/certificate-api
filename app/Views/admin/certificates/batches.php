<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-0 text-gray-800">
                <?= esc($title) ?>
            </h1>
            <p class="mb-0">History of all certificate generation runs.</p>
        </div>
        <div class="col-md-4 text-right">
            <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Certificate
            </a>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Generation History</h6>
        </div>
        <div class="card-body">
            <?php if (empty($batches)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-history fa-3x text-gray-300 mb-3"></i>
                    <p class="lead">No generation batches found yet.</p>
                    <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/data') ?>" class="btn btn-primary">
                        Generate Certificates Now
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Generated Date</th>
                                <th>Total Certificates</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-info">
                                            <?= esc($batch['batch_id']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= date('Y-m-d H:i:s', strtotime($batch['last_generated'])) ?>
                                    </td>
                                    <td>
                                        <?= esc($batch['count']) ?>
                                    </td>
                                    <td>
                                        <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/batches/' . $batch['batch_id']) ?>"
                                            class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> View Results
                                        </a>
                                        <form
                                            action="<?= base_url('admin/certificates/' . $certificate['id'] . '/batches/' . $batch['batch_id'] . '/delete') ?>"
                                            method="POST" style="display:inline;"
                                            onsubmit="return confirm('Are you sure you want to permanently delete this generation batch and all its files from S3?');">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>