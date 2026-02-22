<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/clients') ?>" style="color: var(--text-dim);"><i class="fas fa-arrow-left"></i></a>
        <h1>Create New Client</h1>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <form action="<?= base_url('admin/clients/save') ?>" method="POST">
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Client Name /
                    Organization</label>
                <input type="text" name="name" required placeholder="e.g. Acme Corporation"
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Contact Email</label>
                    <input type="email" name="contact_email" placeholder="email@example.com"
                        style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Contact Phone</label>
                    <input type="text" name="contact_phone" placeholder="+1 234 567 890"
                        style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Website URL</label>
                <input type="url" name="website_url" placeholder="https://example.com"
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Subscription Plan</label>
                <select name="plan"
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                    <option value="free">Free</option>
                    <option value="basic">Basic</option>
                    <option value="pro">Pro</option>
                    <option value="enterprise">Enterprise</option>
                </select>
            </div>

            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    <i class="fas fa-save"></i> Create Client
                </button>
            </div>
        </div>
    </form>
</div>
<?= $this->endSection() ?>