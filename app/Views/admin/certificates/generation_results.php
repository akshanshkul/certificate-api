<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" style="color: var(--text-dim);"><i
                class="fas fa-arrow-left"></i></a>
        <h1>Generation Results</h1>
    </div>
</div>

<div class="card" style="margin-bottom: 2rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
        <div>
            <h2 style="font-size: 1.1rem; margin: 0;">Summary for:
                <?= esc($certificate['name']) ?>
            </h2>
            <p style="color: var(--text-dim); font-size: 0.9rem; margin-top: 5px;">
                <?= $message ?>
            </p>
        </div>
        <div style="display: flex; gap: 10px;">
            <div
                style="padding: 10px 20px; background: rgba(16, 185, 129, 0.1); border-radius: 12px; border: 1px solid var(--accent); text-align: center;">
                <div style="font-size: 1.2rem; font-weight: bold; color: var(--accent);">
                    <?= $success_count ?>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Success</div>
            </div>
            <?php if ($fail_count > 0): ?>
                <div
                    style="padding: 10px 20px; background: rgba(239, 68, 68, 0.1); border-radius: 12px; border: 1px solid #ef4444; text-align: center;">
                    <div style="font-size: 1.2rem; font-weight: bold; color: #ef4444;">
                        <?= $fail_count ?>
                    </div>
                    <div style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Failed</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- NEW: Final Downloads Section -->
    <div
        style="background: rgba(255,255,255,0.03); border: 1px dashed var(--border); border-radius: 12px; padding: 20px; margin-bottom: 2rem; display: flex; flex-wrap: wrap; gap: 20px; align-items: center;">
        <div style="flex: 1;">
            <h3 style="margin: 0; font-size: 1rem; color: var(--accent);">Bulk Downloads</h3>
            <p style="margin: 5px 0 0 0; font-size: 0.8rem; color: var(--text-dim);">Access all generated certificates
                in your preferred format.</p>
        </div>
        <div style="display: flex; gap: 15px;">
            <?php if (!empty($combined_pdf_url)): ?>
                <a href="<?= $combined_pdf_url ?>" target="_blank" class="btn btn-primary"
                    style="background: linear-gradient(135deg, #FF512F 0%, #DD2476 100%); border: none;">
                    <i class="fas fa-file-pdf"></i> Download All (one PDF)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Recipient Name</th>
                <th>Certificate ID</th>
                <th>S3 Storage URL</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $res): ?>
                <tr>
                    <td><strong>
                            <?= esc($res['name']) ?>
                        </strong></td>
                    <td>#
                        <?= $res['id'] ?>
                    </td>
                    <td
                        style="font-family: monospace; font-size: 0.8rem; color: var(--text-dim); max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?= $res['url'] ?>
                    </td>
                    <td>
                        <a href="<?= $res['url'] ?>" target="_blank" class="btn btn-primary"
                            style="padding: 5px 12px; font-size: 0.8rem;">
                            <i class="fas fa-download"></i> View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 2rem; display: flex; gap: 15px;">
        <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" class="btn"
            style="background: rgba(255,255,255,0.05); color: white; border: 1px solid var(--border);">
            <i class="fas fa-chevron-left"></i> Back to Certificate
        </a>
        <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/generate') ?>" class="btn btn-primary"
            onclick="return confirm('Trigger another batch?')">
            <i class="fas fa-redo"></i> Regenerate All
        </a>
    </div>
</div>
<?= $this->endSection() ?>