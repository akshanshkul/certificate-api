<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" style="color: var(--text-dim);"><i
                class="fas fa-arrow-left"></i></a>
        <h1>Step 2: Upload Assets</h1>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <form action="<?= base_url('admin/certificates/' . $certificate['id'] . '/upload') ?>" method="POST"
        enctype="multipart/form-data">
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <div
                style="padding: 1rem; background: rgba(139, 92, 246, 0.1); border-radius: 16px; color: var(--primary-light);">
                <i class="fas fa-info-circle"></i> Uploading assets for:
                <strong><?= esc($certificate['name']) ?></strong>
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Template File (PDF, PNG, or
                    JPG)</label>
                <input type="file" name="template_file" accept=".pdf,.png,.jpg,.jpeg" required
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                <p style="font-size: 0.8rem; color: var(--text-dim); margin-top: 5px;">PDF, PNG, and JPG files are
                    supported.</p>
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Logos / Additional Assets
                    (Optional)</label>
                <input type="file" name="logos[]" accept=".png,.jpg,.jpeg" multiple
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                <p style="font-size: 0.8rem; color: var(--text-dim); margin-top: 5px;">You can select multiple image
                    files (PNG/JPG).</p>
            </div>

            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    Upload & Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </form>
</div>
<?= $this->endSection() ?>