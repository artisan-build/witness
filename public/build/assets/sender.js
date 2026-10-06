import * as pdfjsLib from './pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('./pdf.worker.min.mjs', import.meta.url).toString();

const key = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
const pdfDocuments = new WeakMap();

const register = () => {
    window.Alpine.data('witnessRepeater', (initialRows, emptyRow) => ({
        rows: initialRows.map((row) => ({ ...row, _key: key() })),

        add() {
            this.rows.push({ ...emptyRow, _key: key() });
        },

        remove(index) {
            this.rows.splice(index, 1);
        },

        move(index, offset) {
            const destination = index + offset;

            if (destination < 0 || destination >= this.rows.length) return;

            const [row] = this.rows.splice(index, 1);
            this.rows.splice(destination, 0, row);
        },
    }));

    window.Alpine.data('witnessPlacement', (pdfUrl) => ({
        assignees: [],
        currentPage: 1,
        drag: null,
        fields: [],
        loading: true,
        pageCount: 1,
        colors: ['#a64525', '#366a86', '#54705e', '#8a5d1d', '#71558d', '#326f6a'],

        init() {
            const data = JSON.parse(this.$root.querySelector('[data-placement-data]').textContent);
            this.assignees = data.assignees;
            this.fields = data.fields.map((field) => ({
                ...field,
                _key: key(),
                assignee_id: Number(field.assignee_id),
                page: Number(field.page),
            }));
            this.$nextTick(() => this.loadPdf(pdfUrl));
        },

        get serializedFields() {
            return JSON.stringify(this.fields.map(({ _key, ...field }) => field));
        },

        get visibleFields() {
            return this.fields.filter((field) => field.page === this.currentPage);
        },

        persist() {
            this.fields = [...this.fields];
        },

        addField(type) {
            if (this.assignees.length === 0) return;

            const checkbox = type === 'checkbox';
            this.fields.push({
                _key: key(),
                assignee_id: Number(this.assignees[0].id),
                page: this.currentPage,
                type,
                required: true,
                position_x: .1,
                position_y: .1,
                width: checkbox ? .06 : .26,
                height: checkbox ? .06 : .08,
            });
        },

        removeField(fieldKey) {
            this.fields = this.fields.filter((field) => field._key !== fieldKey);
        },

        fieldStyle(field) {
            const colorIndex = Math.max(0, this.assignees.findIndex((item) => Number(item.id) === Number(field.assignee_id)));

            return {
                left: `${field.position_x * 100}%`,
                top: `${field.position_y * 100}%`,
                width: `${field.width * 100}%`,
                height: `${field.height * 100}%`,
                '--field-color': this.colors[colorIndex % this.colors.length],
            };
        },

        startField(event, field) {
            if (event.target.closest('select,button')) return;

            const box = event.currentTarget;
            const boxBounds = box.getBoundingClientRect();

            if (event.clientX > boxBounds.right - 14 && event.clientY > boxBounds.bottom - 14) return;

            const bounds = this.$refs.layer.getBoundingClientRect();
            this.drag = {
                field,
                bounds,
                x: event.clientX,
                y: event.clientY,
                left: field.position_x,
                top: field.position_y,
            };
            box.setPointerCapture(event.pointerId);
        },

        moveField(event) {
            if (this.drag === null) return;

            const { field, bounds, x, y, left, top } = this.drag;
            field.position_x = Math.max(0, Math.min(1 - field.width, left + (event.clientX - x) / bounds.width));
            field.position_y = Math.max(0, Math.min(1 - field.height, top + (event.clientY - y) / bounds.height));
        },

        stopField() {
            this.drag = null;
            this.persist();
        },

        observeField(element, field) {
            let ready = false;
            const observer = new ResizeObserver(() => {
                if (!ready) {
                    ready = true;
                    return;
                }

                if (!element.isConnected) {
                    observer.disconnect();
                    return;
                }

                const bounds = this.$refs.layer.getBoundingClientRect();
                if (bounds.width === 0 || bounds.height === 0) return;

                field.width = Math.max(.01, Math.min(1 - field.position_x, element.offsetWidth / bounds.width));
                field.height = Math.max(.01, Math.min(1 - field.position_y, element.offsetHeight / bounds.height));
                this.persist();
            });
            observer.observe(element);
        },

        async loadPdf(url) {
            try {
                const pdfDocument = await pdfjsLib.getDocument({ url, isEvalSupported: false }).promise;
                pdfDocuments.set(this, pdfDocument);
                this.pageCount = pdfDocument.numPages;
                await this.renderPage();
            } catch (error) {
                this.loading = false;
                this.$refs.layer.textContent = 'The PDF preview could not be loaded.';
                console.error('PDF preview failed', error);
            }
        },

        async renderPage() {
            const pdfDocument = pdfDocuments.get(this);
            if (!pdfDocument) return;

            this.loading = true;
            const page = await pdfDocument.getPage(this.currentPage);
            const viewport = page.getViewport({ scale: 1.6 });
            const context = this.$refs.canvas.getContext('2d');
            this.$refs.canvas.width = viewport.width;
            this.$refs.canvas.height = viewport.height;
            const renderTask = page.render({ canvasContext: context, viewport });
            await renderTask.promise;
            this.$refs.stage.style.aspectRatio = `${viewport.width} / ${viewport.height}`;
            this.loading = false;
        },

        async previousPage() {
            if (this.currentPage <= 1) return;
            this.currentPage--;
            await this.renderPage();
        },

        async nextPage() {
            if (this.currentPage >= this.pageCount) return;
            this.currentPage++;
            await this.renderPage();
        },
    }));
};

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register, { once: true });
}
