<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/certificates') ?>" style="color: var(--text-dim);"><i
                class="fas fa-arrow-left"></i></a>
        <h1>Create New Certificate</h1>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <div
        style="margin-bottom: 2rem; padding: 1rem; background: rgba(139, 92, 246, 0.1); border-radius: 16px; color: var(--primary-light);">
        <i class="fas fa-user-check"></i> First, select a client for whom you are creating this certificate.
    </div>

    <form action="<?= base_url('admin/certificates/create') ?>" method="POST">
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Certificate Name</label>
                <input type="text" name="name" required placeholder="e.g. Employee of the Month"
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Select Client</label>
                <select name="client_id" required
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                    <option value="">-- Choose Client --</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= $client['id'] ?>" <?= (isset($selected_client_id) && $selected_client_id == $client['id']) ? 'selected' : '' ?>>
                            <?= esc($client['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Orientation</label>
                    <select name="orientation"
                        style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                        <option value="L">Landscape</option>
                        <option value="P">Portrait</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Paper Size</label>
                    <select name="paper_size"
                        style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                        <option value="A4">A4</option>
                        <option value="LETTER">Letter</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    Create & Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </form>
</div>
<?= $this->endSection() ?>