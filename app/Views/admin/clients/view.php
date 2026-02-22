<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/clients') ?>" style="color: var(--text-dim);"><i class="fas fa-arrow-left"></i></a>
        <h1>Client:
            <?= esc($client['name']) ?>
        </h1>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="<?= base_url('admin/certificates/upload/' . $client['id']) ?>" class="btn btn-primary">
            <i class="fas fa-file-upload"></i> Upload Template
        </a>
        <div class="badge badge-success"><?= esc($client['status']) ?></div>
    </div>
</div>

<div class="stats-grid">
    <div class="card" style="grid-column: span 2;">
        <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem;">Information</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <label style="display:block; color: var(--text-dim); font-size: 0.8rem;">Email</label>
                <p>
                    <?= esc($client['contact_email'] ?? 'N/A') ?>
                </p>
            </div>
            <div>
                <label style="display:block; color: var(--text-dim); font-size: 0.8rem;">Phone</label>
                <p>
                    <?= esc($client['contact_phone'] ?? 'N/A') ?>
                </p>
            </div>
            <div>
                <label style="display:block; color: var(--text-dim); font-size: 0.8rem;">Website</label>
                <p>
                    <?= esc($client['website_url'] ?? 'N/A') ?>
                </p>
            </div>
            <div>
                <label style="display:block; color: var(--text-dim); font-size: 0.8rem;">Plan</label>
                <p style="text-transform: capitalize;">
                    <?= esc($client['plan']) ?>
                </p>
            </div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
    <div class="card">
        <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem;">Certificates by this Client</h2>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certificates as $cert): ?>
                    <tr>
                        <td style="color: var(--text-main);"><?= esc($cert['name']) ?></td>
                        <td><?= date('M j, Y', strtotime($cert['created_at'])) ?></td>
                        <td>
                            <a href="<?= base_url('admin/certificates/' . $cert['id']) ?>" style="color: var(--primary);"><i
                                    class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($certificates)): ?>
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 2rem;">No certificates found for this client.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem;">API Key Management</h2>

        <form action="<?= base_url('admin/clients/' . $client['id'] . '/generate-key') ?>" method="POST"
            style="margin-bottom: 2rem;">
            <div style="display: flex; gap: 10px;">
                <input type="text" name="label" placeholder="Key Label (e.g. Production)" required
                    style="flex-grow: 1; padding: 10px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                <button type="submit" class="btn btn-primary">Generate</button>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Label</th>
                    <th>API Key</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($api_keys as $key): ?>
                    <tr>
                        <td><?= esc($key['label']) ?></td>
                        <td><code><?= esc($key['api_key']) ?></code></td>
                        <td>
                            <?php if ($key['revoked']): ?>
                                <span class="badge" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">Revoked</span>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$key['revoked']): ?>
                                <a href="<?= base_url('admin/clients/revoke-key/' . $key['api_key']) ?>" style="color: #ef4444;"
                                    onclick="return confirm('Revoke this key?')"><i class="fas fa-ban"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($api_keys)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem;">No API keys generated.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>