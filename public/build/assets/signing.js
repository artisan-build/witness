import * as pdfjsLib from './pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('./pdf.worker.min.mjs', import.meta.url).toString();

const viewer = document.querySelector('[data-pdf-url]');

if (viewer) {
    const canvas = viewer.querySelector('[data-testid="signer-pdf-canvas"]');
    const current = viewer.querySelector('[data-page-number]');
    const count = viewer.querySelector('[data-page-count]');
    const previous = viewer.querySelector('[data-page-prev]');
    const next = viewer.querySelector('[data-page-next]');
    let pageNumber = 1;

    try {
        const documentProxy = await pdfjsLib.getDocument({
            url: viewer.dataset.pdfUrl,
            isEvalSupported: false,
        }).promise;
        count.textContent = String(documentProxy.numPages);

        const render = async () => {
            const pageProxy = await documentProxy.getPage(pageNumber);
            const viewport = pageProxy.getViewport({ scale: 1.6 });
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            await pageProxy.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
            current.textContent = String(pageNumber);
            previous.disabled = pageNumber === 1;
            next.disabled = pageNumber === documentProxy.numPages;
        };

        previous.addEventListener('click', async () => {
            if (pageNumber > 1) pageNumber--;
            await render();
        });
        next.addEventListener('click', async () => {
            if (pageNumber < documentProxy.numPages) pageNumber++;
            await render();
        });
        await render();
    } catch {
        viewer.querySelector('.signing-pdf-stage').textContent = 'The document preview could not be loaded.';
    }
}

document.querySelectorAll('[data-signature-control]').forEach((control) => {
    const canvas = control.querySelector('[data-signature-canvas]');
    const value = control.querySelector('[data-signature-value]');
    const clear = control.querySelector('[data-signature-clear]');
    const context = canvas.getContext('2d');
    let drawing = false;

    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.lineWidth = 4;
    context.strokeStyle = '#172b2a';

    const point = (event) => {
        const bounds = canvas.getBoundingClientRect();
        return {
            x: (event.clientX - bounds.left) * (canvas.width / bounds.width),
            y: (event.clientY - bounds.top) * (canvas.height / bounds.height),
        };
    };

    canvas.addEventListener('pointerdown', (event) => {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        const start = point(event);
        context.beginPath();
        context.moveTo(start.x, start.y);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) return;
        const next = point(event);
        context.lineTo(next.x, next.y);
        context.stroke();
    });
    const finish = () => {
        if (!drawing) return;
        drawing = false;
        value.value = canvas.toDataURL('image/png');
        control.querySelector('input[type="radio"][value="drawn"]').checked = true;
    };
    canvas.addEventListener('pointerup', finish);
    canvas.addEventListener('pointercancel', finish);
    clear.addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        value.value = '';
    });
});
