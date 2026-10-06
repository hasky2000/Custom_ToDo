// 自動保存を順番に送信し、古いレスポンスで新しい編集内容を上書きしない。
class SettingsSaveQueue {
    constructor({ send, onSaving, onSaved, onError, delay = 350 }) {
        this.send = send;
        this.onSaving = onSaving;
        this.onSaved = onSaved;
        this.onError = onError;
        this.delay = delay;
        this.revision = 0;
        this.pending = null;
        this.timer = null;
        this.running = null;
        this.active = true;
    }

    schedule(settings, message, immediate = false) {
        if (!this.active) return;
        this.pending = {
            settings: { colors: { ...settings.colors }, appearance: { ...settings.appearance } },
            message,
            revision: ++this.revision,
        };
        clearTimeout(this.timer);
        if (immediate) return this.flush();
        this.timer = setTimeout(() => this.flush(), this.delay);
    }

    flush() {
        clearTimeout(this.timer);
        if (!this.active) return Promise.resolve();
        if (!this.running) {
            this.running = this.drain().finally(() => {
                this.running = null;
                if (this.active && this.pending) this.flush();
            });
        }
        return this.running;
    }

    async drain() {
        while (this.active && this.pending) {
            clearTimeout(this.timer);
            const job = this.pending;
            this.pending = null;
            this.onSaving?.(job.revision === this.revision);
            try {
                const data = await this.send(job.settings);
                if (this.active) this.onSaved?.(data, job.revision === this.revision, job.message);
            } catch (error) {
                if (this.active) this.onError?.(error, job.revision === this.revision);
            }
        }
    }

    dispose() {
        this.active = false;
        this.pending = null;
        clearTimeout(this.timer);
    }
}

if (typeof module !== 'undefined') {
    module.exports = SettingsSaveQueue;
}
