<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/certificates') ?>" style="color: var(--text-dim);"><i
                class="fas fa-arrow-left"></i></a>
        <h1>Certificate Details:
            <?= esc($certificate['name']) ?>
        </h1>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 2rem;">
    <div class="card">
        <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem;">Recent Generations</h2>
        <table>
            <thead>
                <tr>
                    <th>Generation ID</th>
                    <th>Generated On</th>
                    <th>Link</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($generations as $gen): ?>
                    <tr>
                        <td>#
                            <?= $gen['id'] ?>
                        </td>
                        <td>
                            <?= date('M j, Y H:i', strtotime($gen['created_at'])) ?>
                        </td>
                        <td><a href="<?= $gen['public_url'] ?>" target="_blank" style="color: var(--accent);"><i
                                    class="fas fa-external-link-alt"></i></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem;">Actions</h2>
        <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 2rem;">
            <form action="<?= base_url('admin/certificates/' . $certificate['id'] . '/generate') ?>" method="POST">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    <i class="fas fa-magic"></i> Trigger Batch Generation
                </button>
            </form>
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/batches') ?>" class="btn"
                style="background: rgba(147, 197, 253, 0.1); color: #93c5fd; border: 1px solid #93c5fd; justify-content: center;">
                <i class="fas fa-history"></i> View Generation History
            </a>
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/preview') ?>" target="_blank"
                class="btn"
                style="background: rgba(16, 185, 129, 0.1); color: var(--accent); border: 1px solid var(--accent); justify-content: center;">
                <i class="fas fa-eye"></i> Preview Sample Certificate
            </a>
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/download-pdf') ?>" class="btn"
                style="background: rgba(59, 130, 246, 0.1); color: #60a5fa; border: 1px solid #60a5fa; justify-content: center;">
                <i class="fas fa-file-pdf"></i> Download All (Single PDF)
            </a>
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/upload') ?>" class="btn"
                style="background: rgba(255,255,255,0.05); color: var(--text-main); justify-content: center;">
                <i class="fas fa-upload"></i> Re-upload Assets
            </a>
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/data') ?>" class="btn"
                style="background: rgba(255,255,255,0.05); color: var(--text-main); justify-content: center;">
                <i class="fas fa-table"></i> Re-upload Data Sheet
            </a>
            <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/setup') ?>" class="btn"
                style="background: rgba(255,255,255,0.05); color: var(--text-main); justify-content: center;">
                <i class="fas fa-cog"></i> Adjust Variable Setup
            </a>
            <hr style="border: none; border-top: 1px solid var(--border); margin: 10px 0;">
            <form action="<?= base_url('admin/certificates/' . $certificate['id'] . '/delete') ?>" method="POST"
                onsubmit="return confirm('CRITICAL WARNING: This will permanently delete this certificate, all uploaded data, and all generated files from S3. This action cannot be undone. Proceed?');">
                <button type="submit" class="btn"
                    style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; width: 100%; justify-content: center;">
                    <i class="fas fa-trash-alt"></i> Permanently Delete Certificate
                </button>
            </form>
        </div>

        <h2 style="font-size: 1.1rem; margin-bottom: 1.5rem;">Settings</h2>
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <div>
                <label
                    style="color: var(--text-dim); font-size: 0.8rem; margin-bottom: 5px; display: block;">Orientation</label>
                <div style="background: #0f172a; padding: 10px; border-radius: 8px;">
                    <?= $certificate['orientation'] == 'L' ? 'Landscape' : 'Portrait' ?>
                </div>
            </div>
            <div>
                <label style="color: var(--text-dim); font-size: 0.8rem; margin-bottom: 5px; display: block;">Paper
                    Size</label>
                <div style="background: #0f172a; padding: 10px; border-radius: 8px;">
                    <?= esc($certificate['paper_size'] ?? 'A4') ?>
                </div>
            </div>
            <hr style="border: none; border-top: 1px solid var(--border); margin: 10px 0;">
            <button class="btn btn-primary" style="width: 100%; justify-content: center;"
                onclick="alert('Feature coming soon')">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </div>
</div>
<?= $this->endSection() ?>