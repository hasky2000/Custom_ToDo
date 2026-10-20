// DBの日時はタイムゾーンを保ったまま解釈し、残り時間の計算はUTCの時刻で行う。
const taskSchedule = {
    labels: { unscheduled: '日時なし', deadline: '期限のみ', period: '期間あり' },

    parseDate(value) {
        if (typeof value !== 'string') return new Date(NaN);
        return new Date(value.replace(' ', 'T')
            .replace(/(\.\d{3})\d+/, '$1')
            .replace(/([+-]\d{2})$/, '$1:00'));
    },

    typeOf(task) {
        return task.task_type ?? (task.scheduled_at ? 'deadline' : 'unscheduled');
    },

    dayKey(value) {
        const date = value instanceof Date ? value : this.parseDate(value);
        if (Number.isNaN(date.getTime())) return null;
        const pad = number => String(number).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    },

    groupByDate(tasks) {
        const groups = new Map();
        for (const task of tasks) {
            // 期間タスクも終了日（締切日）で分類する。日時なしは最後にまとめる。
            const date = this.typeOf(task) === 'unscheduled' ? null : this.dayKey(task.scheduled_at);
            if (!groups.has(date)) groups.set(date, []);
            groups.get(date).push(task);
        }
        return [...groups].sort(([first], [second]) => {
            if (first === second) return 0;
            if (first === null) return 1;
            if (second === null) return -1;
            return first.localeCompare(second);
        }).map(([date, tasks]) => ({ date, tasks }));
    },

    dateLabel(key, now = Date.now()) {
        if (!key) return '日時なし';
        const [year, month, day] = key.split('-').map(Number);
        const date = new Date(year, month - 1, day, 12);
        const today = new Date(now);
        const tomorrow = new Date(now);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const prefix = key === this.dayKey(today) ? '今日 · ' : key === this.dayKey(tomorrow) ? '明日 · ' : '';
        return prefix + date.toLocaleDateString('ja-JP', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'short' });
    },

    duration(milliseconds) {
        const seconds = Math.max(1, Math.ceil(Math.abs(milliseconds) / 1000));
        if (seconds < 60) return `${seconds}秒`;
        const totalMinutes = Math.ceil(seconds / 60);
        const days = Math.floor(totalMinutes / 1440);
        const hours = Math.floor(totalMinutes % 1440 / 60);
        const minutes = totalMinutes % 60;
        return [days ? `${days}日` : '', hours ? `${hours}時間` : '', minutes ? `${minutes}分` : ''].filter(Boolean).join(' ');
    },

    timing(task, now = Date.now()) {
        const type = this.typeOf(task);
        if (type === 'unscheduled') return { status: 'unscheduled', text: '' };
        const due = this.parseDate(task.scheduled_at).getTime();
        if (Number.isNaN(due)) return { status: 'invalid', text: '締切日時を確認してください。' };
        const remaining = due - now;
        if (remaining === 0) return { status: 'overdue', text: '締切になりました' };
        if (remaining < 0) return { status: 'overdue', text: `期限超過 ${this.duration(remaining)}` };
        if (type === 'period') {
            const start = this.parseDate(task.starts_at).getTime();
            if (Number.isNaN(start)) return { status: 'invalid', text: '開始日時を確認してください。' };
            if (start > now) {
                return { status: 'upcoming', text: `開始まで ${this.duration(start - now)} ／ 締切まで ${this.duration(remaining)}` };
            }
            return { status: 'active', text: `進行中 ／ 残り ${this.duration(remaining)}` };
        }
        return { status: 'deadline', text: `残り ${this.duration(remaining)}` };
    },
};

if (typeof module !== 'undefined') module.exports = taskSchedule;
