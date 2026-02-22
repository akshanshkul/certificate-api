<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <h1>Certificates Explorer</h1>
    <a href="<?= base_url('admin/certificates/new') ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create Certificate
    </a>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Certificate Name</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($certificates as $cert): ?>
                <tr>
                    <td>#
                        <?= $cert['id'] ?>
                    </td>
                    <td>
                        <?= esc($cert['client_name']) ?>
                    </td>
                    <td style="color: var(--text-main); font-weight: 500;">
                        <?= esc($cert['name']) ?>
                    </td>
                    <td>
                        <?= date('M j, Y', strtotime($cert['created_at'])) ?>
                    </td>
                    <td>
                        <a href="<?= base_url('admin/certificates/' . $cert['id']) ?>"
                            style="color: var(--primary); margin-right: 15px;" title="View Details"><i
                                class="fas fa-eye"></i></a>
                        <form action="<?= base_url('admin/certificates/' . $cert['id'] . '/delete') ?>" method="POST"
                            style="display:inline;"
                            onsubmit="return confirm('WARNING: This will permanently delete this certificate, all uploaded data, and all generated files from S3. This action cannot be undone. Proceed?');">
                            <button type="submit"
                                style="background:none; border:none; color: #ef4444; padding:0; cursor:pointer;"
                                title="Permanently Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>