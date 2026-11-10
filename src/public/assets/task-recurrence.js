(function (root, factory) {
    const recurrence = factory();
    if (typeof module === 'object' && module.exports) module.exports = recurrence;
    else root.taskRecurrence = recurrence;
})(typeof globalThis === 'object' ? globalThis : this, function () {
    const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    const pad = value => String(value).padStart(2, '0');
    const localDate = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const fields = form => ({
        type: form.elements.namedItem('repeat_type'),
        month: form.elements.namedItem('repeat_month_date'),
        weekday: form.elements.namedItem('repeat_weekday'),
        time: form.elements.namedItem('repeat_time'),
    });

    function dateForDay(day) {
        const date = new Date();
        date.setDate(1);
        while (day > new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()) date.setMonth(date.getMonth() + 1);
        date.setDate(day);
        return localDate(date);
    }

    function updateForm(form) {
        const enabled = form.dataset.repeating === 'true';
        const input = fields(form);
        form.querySelector('[data-repeat-settings]').hidden = !enabled;
        const toggle = form.querySelector('[data-repeat-toggle]');
        toggle.setAttribute('aria-expanded', String(enabled));
        toggle.setAttribute('aria-pressed', String(enabled));
        input.type.disabled = !enabled;
        input.month.disabled = !enabled || input.type.value !== 'monthly';
        input.weekday.disabled = !enabled || input.type.value !== 'weekly';
        input.time.disabled = !enabled;
        input.month.required = !input.month.disabled;
        input.weekday.required = !input.weekday.disabled;
        input.time.required = enabled;
        form.querySelector('[data-repeat-month]').hidden = input.month.disabled;
        form.querySelector('[data-repeat-week]').hidden = input.weekday.disabled;
        for (const field of Object.values(input)) field.setCustomValidity('');
        if (!enabled) return '';
        if (!['daily', 'weekly', 'monthly'].includes(input.type.value)) input.type.setCustomValidity('繰り返しの単位を選択してください。');
        if (!input.month.disabled && (!input.month.value || input.month.validity.badInput)) input.month.setCustomValidity('繰り返す日付を選択してください。');
        if (!input.weekday.disabled && !/^[0-6]$/.test(input.weekday.value)) input.weekday.setCustomValidity('曜日を選択してください。');
        if (!/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?$/.test(input.time.value)) input.time.setCustomValidity('繰り返す時刻を指定してください。');
        return Object.values(input).find(field => field.validationMessage)?.validationMessage ?? '';
    }

    function fillForm(form, task = {}) {
        const input = fields(form);
        const enabled = task.repeat_type && task.repeat_type !== 'none';
        form.dataset.repeating = String(Boolean(enabled));
        form.dataset.repeatTimezone = task.repeat_timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;
        input.type.value = enabled ? task.repeat_type : 'daily';
        input.month.value = enabled && task.repeat_type === 'monthly' ? dateForDay(task.repeat_day) : localDate(new Date());
        input.weekday.value = String(enabled && task.repeat_type === 'weekly' ? task.repeat_day : new Date().getDay());
        input.time.value = (task.repeat_time ?? '09:00').slice(0, 5);
        updateForm(form);
    }

    function payload(form) {
        if (form.dataset.repeating !== 'true') {
            return { repeat_type: 'none', repeat_day: null, repeat_time: null, repeat_timezone: null };
        }
        const input = fields(form);
        return {
            repeat_type: input.type.value,
            repeat_day: input.type.value === 'monthly' ? Number(input.month.value.slice(-2))
                : input.type.value === 'weekly' ? Number(input.weekday.value) : null,
            repeat_time: input.time.value,
            repeat_timezone: form.dataset.repeatTimezone,
        };
    }

    function bindForm(form, onChange) {
        fillForm(form);
        form.querySelector('[data-repeat-toggle]').addEventListener('click', () => {
            form.dataset.repeating = String(form.dataset.repeating !== 'true');
            onChange();
        });
    }

    function label(task) {
        const time = (task.repeat_time ?? '').slice(0, 5);
        if (task.repeat_type === 'daily') return `毎日 ${time}`;
        if (task.repeat_type === 'weekly') return `毎週${weekdays[task.repeat_day]}曜日 ${time}`;
        if (task.repeat_type === 'monthly') return `毎月${task.repeat_day}日 ${time}`;
        return '';
    }

    return { bindForm, updateForm, fillForm, payload, label };
});
