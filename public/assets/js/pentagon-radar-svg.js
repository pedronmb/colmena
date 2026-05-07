/**
 * Gráfico radar pentagonal (SVG), sin dependencias.
 * @global {typeof ColmenaPentagonRadar}
 */
window.ColmenaPentagonRadar = (function () {
    const NS = "http://www.w3.org/2000/svg";

    function angleForVertex(i, n, phase) {
        const p = phase === undefined ? -Math.PI / 2 : phase;
        return p + (i * 2 * Math.PI) / n;
    }

    function point(cx, cy, r, angle) {
        return [cx + r * Math.cos(angle), cy + r * Math.sin(angle)];
    }

    function pentagonPoints(cx, cy, r, n) {
        const pts = [];
        for (let i = 0; i < n; i++) {
            const ang = angleForVertex(i, n);
            const [x, y] = point(cx, cy, r, ang);
            pts.push(`${x.toFixed(2)},${y.toFixed(2)}`);
        }
        return pts.join(" ");
    }

    function dataPolygonPoints(cx, cy, maxR, values, maxValue) {
        const n = values.length;
        const pts = [];
        const cap = maxValue > 0 ? maxValue : 10;
        for (let i = 0; i < n; i++) {
            const ang = angleForVertex(i, n);
            const raw = values[i];
            const v = Math.max(0, Math.min(cap, Number(raw) || 0));
            const rr = (v / cap) * maxR;
            const [x, y] = point(cx, cy, rr, ang);
            pts.push(`${x.toFixed(2)},${y.toFixed(2)}`);
        }
        return pts.join(" ");
    }

    /**
     * @param {HTMLElement} container
     * @param {{
     *   labels: string[],
     *   labelTooltips?: string[],
     *   values: number[],
     *   max?: number,
     *   size?: number,
     *   hasData?: boolean
     * }} options
     */
    function render(container, options) {
        const labels = options.labels || [];
        const labelTooltips = options.labelTooltips || [];
        const values = options.values || [];
        const max = options.max !== undefined && options.max > 0 ? options.max : 10;
        const size = options.size || 260;
        const hasData = Boolean(options.hasData);
        const n = labels.length || 5;
        const cx = size / 2;
        const cy = size / 2;
        const maxR = size * 0.31;
        const labelR = size * 0.4;

        const svg = document.createElementNS(NS, "svg");
        svg.setAttribute("viewBox", `0 0 ${size} ${size}`);
        svg.setAttribute("width", String(size));
        svg.setAttribute("height", String(size));
        svg.setAttribute("class", "pentagon-radar-svg");
        svg.setAttribute("role", "img");
        svg.setAttribute("aria-label", "Perfil en pentágono");

        const rings = 4;
        for (let ring = 1; ring <= rings; ring++) {
            const r = (ring / rings) * maxR;
            const poly = document.createElementNS(NS, "polygon");
            poly.setAttribute("points", pentagonPoints(cx, cy, r, n));
            poly.setAttribute("class", "pentagon-radar-svg__grid");
            svg.appendChild(poly);
        }

        for (let i = 0; i < n; i++) {
            const ang = angleForVertex(i, n);
            const [x, y] = point(cx, cy, maxR, ang);
            const line = document.createElementNS(NS, "line");
            line.setAttribute("x1", String(cx));
            line.setAttribute("y1", String(cy));
            line.setAttribute("x2", String(x));
            line.setAttribute("y2", String(y));
            line.setAttribute("class", "pentagon-radar-svg__axis");
            svg.appendChild(line);
        }

        const vals =
            values.length >= n ? values.slice(0, n) : values.concat(Array(n - values.length).fill(0));
        const dataPoly = document.createElementNS(NS, "polygon");
        dataPoly.setAttribute("points", dataPolygonPoints(cx, cy, maxR, vals, max));
        dataPoly.setAttribute(
            "class",
            hasData
                ? "pentagon-radar-svg__data"
                : "pentagon-radar-svg__data pentagon-radar-svg__data--empty"
        );
        svg.appendChild(dataPoly);

        labels.forEach((label, i) => {
            const ang = angleForVertex(i, n);
            const [x, y] = point(cx, cy, labelR, ang);
            const text = document.createElementNS(NS, "text");
            text.setAttribute("x", String(x));
            text.setAttribute("y", String(y));
            text.setAttribute("text-anchor", "middle");
            text.setAttribute("dominant-baseline", "middle");
            text.setAttribute("class", "pentagon-radar-svg__label");
            text.textContent = label;
            const tip = labelTooltips[i];
            if (tip && String(tip).trim() !== "") {
                const titleEl = document.createElementNS(NS, "title");
                titleEl.textContent = `${label}: ${tip}`;
                text.appendChild(titleEl);
            }
            svg.appendChild(text);
        });

        container.appendChild(svg);
    }

    return { render };
})();
