<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <h1>Clients Management</h1>
    <a href="<?= base_url('admin/clients/new') ?>" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Client
    </a>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Contact Email</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td>#
                        <?= $client['id'] ?>
                    </td>
                    <td style="color: var(--text-main); font-weight: 500;">
                        <?= esc($client['name']) ?>
                    </td>
                    <td>
                        <?= esc($client['contact_email'] ?? 'N/A') ?>
                    </td>
                    <td><span class="badge"
                            style="background: rgba(99, 102, 241, 0.1); color: var(--primary); text-transform: uppercase; font-size: 0.7rem;">
                            <?= esc($client['plan']) ?>
                        </span></td>
                    <td>
                        <span class="badge badge-success">Active</span>
                    </td>
                    <td>
                        <?= date('M j, Y', strtotime($client['created_at'])) ?>
                    </td>
                    <td>
                        <a href="<?= base_url('admin/clients/' . $client['id']) ?>"
                            style="color: var(--primary); margin-right: 15px;" title="View Details"><i
                                class="fas fa-eye"></i></a>
                        <a href="#" style="color: #ef4444;"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>