import * as pdfjsLib from './pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('./pdf.worker.min.mjs', import.meta.url).toString();

document.querySelectorAll('[data-repeater]').forEach((repeater) => {
    const list = repeater.querySelector('[data-repeater-list]');
    const template = repeater.querySelector('[data-repeater-template]');
    const prefix = repeater.dataset.repeater;

    const rename = () => list.querySelectorAll('[data-repeater-row]').forEach((row, index) => {
        row.querySelectorAll('[data-name]').forEach((input) => input.name = `${prefix}[${index}][${input.dataset.name}]`);
    });
    const wire = (row) => {
        row.querySelector('[data-remove-row]').addEventListener('click', () => { row.remove(); rename(); });
        row.querySelector('[data-move-up]').addEventListener('click', () => { if (row.previousElementSibling) list.insertBefore(row, row.previousElementSibling); rename(); });
        row.querySelector('[data-move-down]').addEventListener('click', () => { if (row.nextElementSibling) list.insertBefore(row.nextElementSibling, row); rename(); });
    };

    list.querySelectorAll('[data-repeater-row]').forEach(wire);
    repeater.querySelector('[data-add-row]').addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        list.append(row);
        wire(row);
        rename();
        row.querySelector('input:not([type="hidden"])')?.focus();
    });
    rename();
});

document.querySelectorAll('[data-placement-root]').forEach(async (root) => {
    const data = JSON.parse(root.querySelector('[data-placement-data]').textContent);
    const input = root.querySelector('[data-fields-input]');
    const canvas = root.querySelector('canvas');
    const stage = root.querySelector('[data-pdf-stage]');
    const layer = root.querySelector('[data-field-layer]');
    const pageNumber = root.querySelector('[data-page-number]');
    const pageCount = root.querySelector('[data-page-count]');
    const colors = ['#a64525', '#366a86', '#54705e', '#8a5d1d', '#71558d', '#326f6a'];
    let currentPage = 1;
    let pdf;
    let fields = data.fields.map((field) => ({ ...field, assignee_id: Number(field.assignee_id), page: Number(field.page) }));

    const persist = () => input.value = JSON.stringify(fields.map(({ element, ...field }) => field));
    const renderFields = () => {
        layer.replaceChildren();
        fields.forEach((field, index) => {
            if (field.page !== currentPage) return;
            const box = document.createElement('div');
            box.className = 'witness-field';
            box.dataset.testid = 'placed-field';
            box.style.left = `${field.position_x * 100}%`;
            box.style.top = `${field.position_y * 100}%`;
            box.style.width = `${field.width * 100}%`;
            box.style.height = `${field.height * 100}%`;
            box.style.setProperty('--field-color', colors[data.assignees.findIndex((item) => Number(item.id) === Number(field.assignee_id)) % colors.length]);
            const label = document.createElement('label');
            const select = document.createElement('select');
            const remove = document.createElement('button');
            label.textContent = field.type.replace('_', ' ');
            select.setAttribute('aria-label', `Assign ${field.type}`);
            data.assignees.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.name;
                option.selected = Number(item.id) === Number(field.assignee_id);
                select.append(option);
            });
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove field');
            remove.textContent = '×';
            box.append(label, select, remove);
            field.element = box;
            select.addEventListener('change', (event) => { field.assignee_id = Number(event.target.value); renderFields(); persist(); });
            remove.addEventListener('click', () => { fields.splice(index, 1); renderFields(); persist(); });
            box.addEventListener('pointerdown', (event) => {
                if (event.target.closest('select,button')) return;
                const bounds = layer.getBoundingClientRect();
                const boxBounds = box.getBoundingClientRect();
                if (event.clientX > boxBounds.right - 14 && event.clientY > boxBounds.bottom - 14) return;
                const start = { x: event.clientX, y: event.clientY, left: field.position_x, top: field.position_y };
                box.setPointerCapture(event.pointerId);
                const move = (moveEvent) => {
                    field.position_x = Math.max(0, Math.min(1 - field.width, start.left + (moveEvent.clientX - start.x) / bounds.width));
                    field.position_y = Math.max(0, Math.min(1 - field.height, start.top + (moveEvent.clientY - start.y) / bounds.height));
                    box.style.left = `${field.position_x * 100}%`;
                    box.style.top = `${field.position_y * 100}%`;
                    persist();
                };
                const up = () => { box.removeEventListener('pointermove', move); box.removeEventListener('pointerup', up); };
                box.addEventListener('pointermove', move);
                box.addEventListener('pointerup', up);
            });
            let resizeReady = false;
            new ResizeObserver(() => {
                if (!resizeReady) { resizeReady = true; return; }
                if (!box.isConnected) return;
                const bounds = layer.getBoundingClientRect();
                field.width = Math.max(.01, Math.min(1 - field.position_x, box.offsetWidth / bounds.width));
                field.height = Math.max(.01, Math.min(1 - field.position_y, box.offsetHeight / bounds.height));
                persist();
            }).observe(box);
            layer.append(box);
        });
    };
    const renderPage = async () => {
        layer.classList.add('is-loading');
        const page = await pdf.getPage(currentPage);
        const viewport = page.getViewport({ scale: 1.6 });
        const context = canvas.getContext('2d');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: context, viewport }).promise;
        stage.style.aspectRatio = `${viewport.width} / ${viewport.height}`;
        pageNumber.textContent = currentPage;
        pageCount.textContent = pdf.numPages;
        layer.classList.remove('is-loading');
        renderFields();
    };

    root.querySelectorAll('[data-field-type]').forEach((button) => button.addEventListener('click', () => {
        fields.push({ assignee_id: Number(data.assignees[0].id), page: currentPage, type: button.dataset.fieldType, required: true, position_x: .1, position_y: .1, width: button.dataset.fieldType === 'checkbox' ? .06 : .26, height: button.dataset.fieldType === 'checkbox' ? .06 : .08 });
        renderFields();
        persist();
    }));
    root.querySelector('[data-page-prev]').addEventListener('click', async () => { if (currentPage > 1) { currentPage--; await renderPage(); } });
    root.querySelector('[data-page-next]').addEventListener('click', async () => { if (currentPage < pdf.numPages) { currentPage++; await renderPage(); } });
    persist();
    layer.classList.add('is-loading');
    try {
        pdf = await pdfjsLib.getDocument({ url: root.dataset.pdfUrl, isEvalSupported: false }).promise;
        await renderPage();
    } catch (error) {
        layer.classList.remove('is-loading');
        layer.textContent = 'The PDF preview could not be loaded.';
        console.error('PDF preview failed', error);
    }
});
