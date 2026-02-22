<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" style="color: var(--text-dim);"><i
                class="fas fa-arrow-left"></i></a>
        <h1>Step 3: Data Entry</h1>
    </div>
</div>

<div class="card" style="max-width: 600px;">
    <div
        style="margin-bottom: 2rem; padding: 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 16px; color: var(--accent);">
        <i class="fas fa-check-circle"></i> Assets uploaded! Now upload your CSV/Excel data sheet. The headers will be
        used for variable mapping in the next step.
    </div>

    <form id="data-entry-form" action="<?= base_url('admin/certificates/' . $certificate['id'] . '/data') ?>"
        method="POST" enctype="multipart/form-data">
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-dim);">Data Sheet (CSV or
                    Excel)</label>
                <input type="file" name="data_sheet" id="data_sheet" accept=".csv,.xlsx,.xls" required
                    style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: 12px; color: white;">
                <p style="font-size: 0.8rem; color: var(--text-dim); margin-top: 5px;">Upload the data for
                    certificate generation.</p>
            </div>

            <div id="validation-section">
                <button type="button" class="btn btn-primary" onclick="validateFile()" id="validate-btn"
                    style="width: 100%; justify-content: center;">
                    <i class="fas fa-search"></i> Validate Data
                </button>
            </div>

            <div id="preview-section" style="display: none;">
                <div
                    style="background: rgba(16, 185, 129, 0.1); padding: 1.5rem; border-radius: 12px; color: var(--accent); margin-bottom: 20px; border: 1px solid var(--accent);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <i class="fas fa-check-circle"></i> <strong id="total-rows-text">Data validated!</strong>
                        </div>
                        <div style="font-size: 0.8rem; background: var(--accent); color: #000; padding: 2px 8px; border-radius: 20px; font-weight: bold;"
                            id="total-rows-badge">
                            0 Rows
                        </div>
                    </div>
                </div>

                <h3 style="font-size: 0.9rem; margin-bottom: 15px; color: var(--text-dim);">Data Preview (First 5 rows)
                </h3>
                <div
                    style="overflow-x: auto; margin-bottom: 20px; background: rgba(0,0,0,0.2); border-radius: 12px; border: 1px solid var(--border);">
                    <table style="width: 100%; font-size: 0.8rem; border-collapse: collapse;" id="preview-table">
                        <thead>
                            <tr id="preview-headers" style="border-bottom: 1px solid var(--border);"></tr>
                        </thead>
                        <tbody id="preview-body"></tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-success"
                    style="width: 100%; justify-content: center; background: #10b981; border: none; padding: 12px; border-radius: 12px; color: white; cursor: pointer;">
                    Save All Data & Continue <i class="fas fa-arrow-right"></i>
                </button>

                <button type="button" onclick="location.reload()"
                    style="width: 100%; margin-top: 10px; background: none; border: 1px solid var(--border); color: var(--text-dim); padding: 10px; border-radius: 12px; cursor: pointer;">
                    Re-upload File
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    async function validateFile() {
        const fileInput = document.getElementById('data_sheet');
        if (!fileInput.files[0]) {
            alert('Please select a file first.');
            return;
        }

        const btn = document.getElementById('validate-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating...';

        const formData = new FormData();
        formData.append('data_sheet', fileInput.files[0]);

        try {
            const response = await fetch('<?= base_url('admin/certificates/' . $certificate['id'] . '/validate-data') ?>', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                // Show preview
                document.getElementById('validation-section').style.display = 'none';
                document.getElementById('preview-section').style.display = 'block';

                // Build headers
                const headRow = document.getElementById('preview-headers');
                headRow.innerHTML = '';
                result.headers.forEach(h => {
                    const th = document.createElement('th');
                    th.style.padding = '10px';
                    th.style.textAlign = 'left';
                    th.textContent = h;
                    headRow.appendChild(th);
                });

                // Build body
                const body = document.getElementById('preview-body');
                body.innerHTML = '';
                result.preview.forEach(row => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
                    result.headers.forEach(h => {
                        const td = document.createElement('td');
                        td.style.padding = '10px';
                        td.textContent = row[h] || '';
                        tr.appendChild(td);
                    });
                    body.appendChild(tr);
                });

                document.getElementById('total-rows-badge').textContent = result.total_rows_count + ' Rows';
                document.getElementById('total_rows_hint')?.setAttribute('value', result.total_rows_count);

            } else {
                alert(result.error || 'Validation failed');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-search"></i> Validate Data';
            }
        } catch (error) {
            console.error(error);
            alert('An error occurred during validation.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> Validate Data';
        }
    }
</script>
<?= $this->endSection() ?>