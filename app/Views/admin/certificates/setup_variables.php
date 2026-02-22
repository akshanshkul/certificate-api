<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<style>
    .setup-container {
        display: grid;
        grid-template-columns: 1fr 450px;
        gap: 20px;
        align-items: start;
    }

    .template-preview-container {
        position: sticky;
        top: 20px;
        background: rgba(0, 0, 0, 0.3);
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow: hidden;
        cursor: crosshair;
        user-select: none;
    }

    #template-img {
        width: 100%;
        display: block;
    }

    .variable-marker {
        position: absolute;
        width: 12px;
        height: 12px;
        background: var(--accent);
        border: 2px solid white;
        border-radius: 50%;
        transform: translate(-50%, -50%);
        pointer-events: none;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
    }

    .variable-label {
        position: absolute;
        white-space: nowrap;
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 10px;
        transform: translate(10px, -50%);
        pointer-events: none;
    }

    .variable-row {
        background: rgba(255, 255, 255, 0.03);
        padding: 15px;
        border-radius: 12px;
        border: 1px solid var(--border);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .variable-row.active {
        background: rgba(59, 130, 246, 0.1);
        border-color: var(--info);
        box-shadow: 0 0 15px rgba(59, 130, 246, 0.1);
    }

    .row-main {
        display: grid;
        grid-template-columns: 1fr 100px 50px;
        gap: 10px;
        align-items: center;
    }

    .row-coords {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 10px;
    }

    input[type="number"],
    select {
        width: 100%;
        padding: 8px 12px;
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid var(--border);
        border-radius: 8px;
        color: white;
        font-size: 0.9rem;
    }

    .marker-active {
        background: #10b981 !important;
        scale: 1.5;
        z-index: 10;
    }
</style>

<div class="header">
    <div style="display: flex; align-items: center; gap: 15px;">
        <a href="<?= base_url('admin/certificates/' . $certificate['id']) ?>" style="color: var(--text-dim);"><i
                class="fas fa-arrow-left"></i></a>
        <h1>Step 4: Variable Mapping</h1>
    </div>
</div>

<div class="setup-container">
    <div class="template-preview-container" id="preview-box">
        <img src="<?= $certificate['template_path'] ?>" id="template-img" alt="Template Preview">
        <!-- Markers will be injected here -->
    </div>

    <div class="controls-side">
        <div class="card" style="margin-bottom: 20px;">
            <div style="margin-bottom: 1rem; color: var(--info); font-size: 0.9rem;">
                <i class="fas fa-magic"></i> <strong>Click Active Variable</strong> then click on the image to set position.
            </div>

            <form action="<?= base_url('admin/certificates/' . $certificate['id'] . '/setup') ?>" method="POST">
                <div id="variables-container" style="display: flex; flex-direction: column; gap: 15px;">
                    <!-- Rows injected via JS -->
                </div>

                <button type="button" class="btn"
                    style="margin-top: 20px; width: 100%; justify-content: center; background: rgba(255,255,255,0.05); color: white; border: 1px dashed var(--border);"
                    onclick="addVariableRow()">
                    <i class="fas fa-plus"></i> Add Variable Mapping
                </button>

                <div
                    style="margin-top: 20px; border-top: 1px solid var(--border); padding-top: 20px; display: flex; flex-direction: column; gap: 10px;">
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                        <i class="fas fa-save"></i> Save All Changes
                    </button>
                    <a href="<?= base_url('admin/certificates/' . $certificate['id'] . '/preview') ?>" target="_blank"
                        class="btn" style="width: 100%; justify-content: center; background: rgba(255,255,255,0.05); border: 1px solid var(--border);">
                        <i class="fas fa-eye"></i> Preview Sample PDF
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let rowCount = 0;
    let activeRowId = null;
    const dataKeys = <?= json_encode($data_keys) ?>;
    const previewBox = document.getElementById('preview-box');
    const templateImg = document.getElementById('template-img');

    function addVariableRow(data = {}) {
        const container = document.getElementById('variables-container');
        const id = rowCount;
        const row = document.createElement('div');
        row.className = 'variable-row';
        row.id = `row-${id}`;
        row.onclick = () => setActiveRow(id);

        let optionsHtml = '<option value="">-- Select Header --</option>';
        dataKeys.forEach(k => {
            const selected = (data.var_key === k) ? 'selected' : '';
            optionsHtml += `<option value="${k}" ${selected}>${k}</option>`;
        });

        row.innerHTML = `
            <div class="row-main">
                <select name="variables[${id}][var_key]" required onchange="updateMarkers()">
                    ${optionsHtml}
                </select>
                <input type="color" name="variables[${id}][color]" value="${data.color || '#000000'}" 
                    style="height: 38px; padding: 2px; cursor: pointer;">
                <button type="button" onclick="event.stopPropagation(); removeRow(${id})" style="color: #ef4444; background: none; border: none; cursor: pointer;">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <div class="row-coords">
                <input type="number" name="variables[${id}][x]" id="x-${id}" placeholder="X" value="${data.x || 0}" required oninput="updateMarkers()">
                <input type="number" name="variables[${id}][y]" id="y-${id}" placeholder="Y" value="${data.y || 0}" required oninput="updateMarkers()">
                <input type="number" name="variables[${id}][font_size]" placeholder="Font Size" value="${data.font_size || 50}" required>
            </div>
            <div style="display: flex; gap: 10px;">
                <select name="variables[${id}][align]" style="width: 100%;">
                    <option value="L" ${data.align === 'L' ? 'selected' : ''}>Left</option>
                    <option value="C" ${data.align === 'C' ? 'selected' : ''}>Center</option>
                    <option value="R" ${data.align === 'R' ? 'selected' : ''}>Right</option>
                </select>
            </div>
        `;

        container.appendChild(row);
        if (activeRowId === null) setActiveRow(id);
        rowCount++;
        updateMarkers();
    }

    function setActiveRow(id) {
        activeRowId = id;
        document.querySelectorAll('.variable-row').forEach(r => r.classList.remove('active'));
        document.getElementById(`row-${id}`)?.classList.add('active');
        updateMarkers();
    }

    function removeRow(id) {
        document.getElementById(`row-${id}`).remove();
        if (activeRowId === id) activeRowId = null;
        updateMarkers();
    }

    previewBox.onclick = (e) => {
        if (activeRowId === null) return;

        const rect = templateImg.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        // Calculate relative coordinates in natural image pixels
        const naturalWidth = templateImg.naturalWidth;
        const naturalHeight = templateImg.naturalHeight;
        const displayedWidth = rect.width;
        const displayedHeight = rect.height;

        const dbX = Math.round((x / displayedWidth) * naturalWidth);
        const dbY = Math.round((y / displayedHeight) * naturalHeight);

        document.getElementById(`x-${activeRowId}`).value = dbX;
        document.getElementById(`y-${activeRowId}`).value = dbY;
        updateMarkers();
    };

    function updateMarkers() {
        // Clear existing markers
        document.querySelectorAll('.variable-marker, .variable-label').forEach(m => m.remove());

        const naturalWidth = templateImg.naturalWidth;
        const naturalHeight = templateImg.naturalHeight;
        const rect = templateImg.getBoundingClientRect();
        
        if (!naturalWidth || !rect.width) return;

        document.querySelectorAll('.variable-row').forEach(row => {
            const id = row.id.split('-')[1];
            const x = parseInt(document.getElementById(`x-${id}`).value) || 0;
            const y = parseInt(document.getElementById(`y-${id}`).value) || 0;
            const select = row.querySelector('select');
            const label = select.options[select.selectedIndex]?.value || 'New';

            const displayX = (x / naturalWidth) * rect.width;
            const displayY = (y / naturalHeight) * rect.height;

            const marker = document.createElement('div');
            marker.className = 'variable-marker' + (parseInt(id) === activeRowId ? ' marker-active' : '');
            marker.style.left = displayX + 'px';
            marker.style.top = displayY + 'px';
            previewBox.appendChild(marker);

            const txt = document.createElement('div');
            txt.className = 'variable-label';
            txt.style.left = displayX + 'px';
            txt.style.top = displayY + 'px';
            txt.textContent = label;
            previewBox.appendChild(txt);
        });
    }

    // Interval to keep markers aligned if container resizes
    setInterval(updateMarkers, 1000);
    window.onresize = updateMarkers;

    templateImg.onload = () => {
        // Existing rows
        <?php if (empty($variables)): ?>
            if (dataKeys.length > 0) {
                dataKeys.forEach((key, index) => {
                    if (index < 3) addVariableRow({ var_key: key, x: 200, y: 300 + (index * 150), font_size: 60, color: '#000000' });
                });
            } else {
                addVariableRow();
            }
        <?php else: ?>
            <?php foreach ($variables as $v): ?>
                addVariableRow(<?= json_encode($v) ?>);
            <?php endforeach; ?>
        <?php endif; ?>
    };
</script>
<?= $this->endSection() ?>
