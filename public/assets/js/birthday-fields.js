(function (global) {
    function daysInMonth(m) {
        return new Date(2000, m, 0).getDate();
    }

    function syncBirthdayHidden(form) {
        const m = form.querySelector('[name="birthday_month"]');
        const d = form.querySelector('[name="birthday_day"]');
        const h = form.querySelector('[name="birthday"]');
        if (!m || !d || !h) return;
        if (!m.value || !d.value) {
            h.value = "";
            return;
        }
        const mm = String(parseInt(m.value, 10)).padStart(2, "0");
        const dd = String(parseInt(d.value, 10)).padStart(2, "0");
        h.value = `${mm}-${dd}`;
    }

    function setDayOptions(daySelect, monthNum) {
        const max = monthNum >= 1 && monthNum <= 12 ? daysInMonth(monthNum) : 31;
        const cur = daySelect.value ? parseInt(daySelect.value, 10) : NaN;
        const opts = ['<option value="">—</option>'];
        for (let di = 1; di <= max; di++) {
            opts.push(`<option value="${di}">${di}</option>`);
        }
        daySelect.innerHTML = opts.join("");
        if (Number.isFinite(cur) && cur >= 1 && cur <= max) {
            daySelect.value = String(cur);
        }
    }

    function wireBirthdayFields(form) {
        const m = form.querySelector('[name="birthday_month"]');
        const d = form.querySelector('[name="birthday_day"]');
        if (!m || !d) return;

        m.addEventListener("change", () => {
            const mo = parseInt(m.value, 10);
            if (Number.isFinite(mo) && mo >= 1 && mo <= 12) {
                setDayOptions(d, mo);
            } else {
                setDayOptions(d, 0);
            }
            syncBirthdayHidden(form);
        });
        d.addEventListener("change", () => {
            syncBirthdayHidden(form);
        });
    }

    function fillBirthdayFields(form, stored) {
        const m = form.querySelector('[name="birthday_month"]');
        const d = form.querySelector('[name="birthday_day"]');
        const h = form.querySelector('[name="birthday"]');
        if (!m || !d || !h) return;
        if (!stored || String(stored).trim() === "") {
            m.value = "";
            setDayOptions(d, 0);
            d.value = "";
            h.value = "";
            return;
        }
        const s = String(stored).trim();
        const p = s.split("-");
        let mo;
        let day;
        if (p.length === 2) {
            mo = parseInt(p[0], 10);
            day = parseInt(p[1], 10);
        } else if (p.length === 3) {
            mo = parseInt(p[1], 10);
            day = parseInt(p[2], 10);
        } else {
            m.value = "";
            setDayOptions(d, 0);
            d.value = "";
            h.value = "";
            return;
        }
        if (!Number.isFinite(mo) || !Number.isFinite(day) || mo < 1 || mo > 12) {
            m.value = "";
            setDayOptions(d, 0);
            d.value = "";
            h.value = "";
            return;
        }
        m.value = String(mo);
        setDayOptions(d, mo);
        const max = daysInMonth(mo);
        if (day >= 1 && day <= max) {
            d.value = String(day);
        } else {
            d.value = "";
        }
        syncBirthdayHidden(form);
    }

    global.ColmenaBirthday = {
        wireBirthdayFields,
        fillBirthdayFields,
        syncBirthdayHidden,
        daysInMonth,
    };
})(typeof window !== "undefined" ? window : globalThis);
