<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <h1>Welcome back, Admin!</h1>
    <div class="badge badge-success">Live Status: Active</div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="label">Total Clients</div>
        <div class="value">
            <?= $stats['total_clients'] ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="label">Templates Created</div>
        <div class="value">
            <?= $stats['total_certificates'] ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="label">Total Generations</div>
        <div class="value">
            <?= $stats['total_generated'] ?>
        </div>
    </div>
</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1.2rem; font-weight: 500;">Recent Certificate Generations</h2>
        <a href="<?= base_url('admin/certificates') ?>"
            style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">View All</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Certificate</th>
                <th>Ver. Code</th>
                <th>Generated On</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_generations as $gen): ?>
                <tr>
                    <td style="color: var(--text-main);">
                        <?= esc($gen['cert_name']) ?>
                    </td>
                    <td><code><?= esc($gen['verification_code'] ?? 'N/A') ?></code></td>
                    <td>
                        <?= date('M j, Y H:i', strtotime($gen['created_at'])) ?>
                    </td>
                    <td>
                        <a href="<?= $gen['public_url'] ?>" target="_blank" style="color: var(--accent);"><i
                                class="fas fa-external-link-alt"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_generations)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 2rem;">No generations yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>