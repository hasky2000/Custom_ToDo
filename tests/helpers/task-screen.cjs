const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// 外部ライブラリなしで、実際のカードとイベントを仮想時計・APIで操作する。
class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.dataset = {};
        this.className = '';
        this.textContent = '';
        this.value = '';
        this.disabled = false;
        this.validity = { badInput: false };
        this.listeners = new Map();
        this.fields = new Map();
        this.style = {
            setProperty(name, value) { this[name] = value; },
            removeProperty(name) { delete this[name]; },
        };
        this.classList = {
            contains: name => this.className.split(/\s+/).includes(name),
            toggle: (name, enabled) => {
                const classes = new Set(this.className.split(/\s+/).filter(Boolean));
                if (enabled) classes.add(name);
                else classes.delete(name);
                this.className = [...classes].join(' ');
            },
            add: name => this.classList.toggle(name, true),
            remove: name => this.classList.toggle(name, false),
        };
        this.elements = { namedItem: name => this.field(name) };
    }
    field(name) {
        if (!this.fields.has(name)) this.fields.set(name, new Element());
        return this.fields.get(name);
    }
    append(...nodes) {
        for (const node of nodes) {
            if (!(node instanceof Element)) continue;
            if (node.parentElement) node.remove();
            node.parentElement = this;
            this.children.push(node);
        }
    }
    insertBefore(node, before) {
        if (node === before) return;
        if (node.parentElement) node.remove();
        const index = before ? this.children.indexOf(before) : this.children.length;
        this.children.splice(index, 0, node);
        node.parentElement = this;
    }
    get nextElementSibling() { return this.parentElement?.children[this.parentElement.children.indexOf(this) + 1] ?? null; }
    get previousElementSibling() { return this.parentElement?.children[this.parentElement.children.indexOf(this) - 1] ?? null; }
    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
    remove() {
        this.parentElement.children = this.parentElement.children.filter(child => child !== this);
        this.parentElement = null;
    }
    setAttribute(name, value) { this[name] = value; }
    removeAttribute(name) { delete this[name]; }
    addEventListener(name, callback) { this.listeners.set(name, callback); }
    reset() {}
    showModal() { this.open = true; }
    close() { this.open = false; }
    focus() {}
    getBoundingClientRect() { return { height: 120 }; }
    setPointerCapture(id) { this.capturedPointer = id; }
    hasPointerCapture(id) { return this.capturedPointer === id; }
    releasePointerCapture() { this.capturedPointer = null; }
    cloneNode() { return new Element(this.tag); }
    checkValidity() { return true; }
    setCustomValidity(message) { this.validationMessage = message; }
    matches(selector) {
        if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
        const input = selector.match(/^input\[type="([^"]+)"\]$/);
        if (input) return this.tag === 'input' && this.type === input[1];
        const data = selector.match(/^\[data-([\w-]+)(?:="([^"]+)")?\]$/);
        if (data) {
            const key = data[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            return this.dataset[key] !== undefined && (data[2] === undefined || String(this.dataset[key]) === data[2]);
        }
        return this.tag === selector;
    }
    querySelectorAll(selector) {
        return this.children.flatMap(child => [
            ...(selector.split(', ').some(part => child.matches(part)) ? [child] : []),
            ...child.querySelectorAll(selector),
        ]);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector); }
    async fire(name, details = {}) {
        await this.listeners.get(name)?.({ target: this, preventDefault() {}, stopPropagation() {}, ...details });
    }
}

async function createScreen() {
    const elements = new Map();
    function element(selector) {
        if (!elements.has(selector)) {
            const node = new Element();
            if (selector === '#task-color-palette') {
                node.textContent = fs.readFileSync(path.join(__dirname, '../../src/app/task_colors.json'), 'utf8');
            }
            // 起動時に参照するフォーム部品は、カードとは別に用意する。
            if (selector.endsWith('-form')) node.querySelector = child => node.field(child);
            elements.set(selector, node);
        }
        return elements.get(selector);
    }
    let now = 0;
    let timerId = 0;
    const timers = new Map();
    const requests = [];
    const data = [1, 2, 3].map(id => ({ id, title: `タスク${id}`, progress: id * 20, task_type: 'unscheduled' }));
    let completionError = null;
    let holdCompletion = false;
    let releaseCompletion;
    let holdTaskLoad = false;
    let releaseTaskLoad;
    let reducedMotion = false;
    let colorError = null;
    let holdColor = false;
    let releaseColor;
    let nextRecurringTask = null;
    const documentListeners = new Map();
    const context = vm.createContext({
        document: {
            body: new Element('body'),
            querySelector: element, querySelectorAll: () => [], createElement: tag => new Element(tag),
            addEventListener(name, listener) { documentListeners.set(name, listener); },
            removeEventListener(name) { documentListeners.delete(name); },
        },
        window: { location: { hash: '#todo' }, addEventListener() {}, scrollTo() {}, matchMedia: () => ({ matches: reducedMotion }) },
        setInterval() {},
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, at: now + delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        requestAnimationFrame() { return 1; },
        cancelAnimationFrame() {},
        taskSchedule: require('../../src/public/assets/task-schedule.js'),
        taskRecurrence: require('../../src/public/assets/task-recurrence.js'),
        async fetch(url, options) {
            requests.push({ url, ...options });
            if (url === '/api/get_tasks.php') {
                const loadedTasks = data.map(task => ({ ...task }));
                if (holdTaskLoad) {
                    holdTaskLoad = false;
                    await new Promise(resolve => { releaseTaskLoad = resolve; });
                }
                return { ok: true, async json() { return { data: loadedTasks }; } };
            }
            if (url === '/api/complete_task.php') {
                if (holdCompletion) await new Promise(resolve => { releaseCompletion = resolve; });
                if (completionError) return { ok: false, async json() { return { error: completionError }; } };
                const index = data.findIndex(task => task.id === JSON.parse(options.body).id);
                const [task] = data.splice(index, 1);
                const next = nextRecurringTask;
                nextRecurringTask = null;
                if (next) data.push(next);
                return { ok: true, async json() { return { data: { ...task, completed: true, progress: 100 }, next_task: next }; } };
            }
            if (url === '/api/reorder_task.php') {
                const ids = JSON.parse(options.body).task_ids;
                data.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
                return { ok: true, async json() { return { data: [] }; } };
            }
            if (url === '/api/set_task_color.php') {
                if (holdColor) await new Promise(resolve => { releaseColor = resolve; });
                if (colorError) return { ok: false, async json() { return { error: colorError }; } };
                const input = JSON.parse(options.body);
                const task = data.find(task => task.id === input.id);
                task.background_color = input.background_color;
                return { ok: true, async json() { return { data: { id: task.id, background_color: task.background_color } }; } };
            }
            throw new Error(`想定外のAPI: ${url}`);
        },
    });
    const source = fs.readFileSync(path.join(__dirname, '../../src/public/assets/app.js'), 'utf8');
    vm.runInContext(source.slice(0, source.lastIndexOf('\ninitialize().catch(')), context);
    const run = code => vm.runInContext(code, context);
    await run('getTasks()');
    return {
        element, requests, run,
        item: id => element('#task-list').querySelector(`[data-task-id="${id}"]`),
        async check(id, checked) {
            const checkbox = this.item(id).querySelector('input[type="checkbox"]');
            assert.equal(checkbox.disabled, false);
            checkbox.checked = checked;
            await checkbox.fire('change');
        },
        async advance(milliseconds) {
            const end = now + milliseconds;
            while (true) {
                const next = [...timers].sort((a, b) => a[1].at - b[1].at)[0];
                if (!next || next[1].at > end) break;
                const [id, timer] = next;
                now = timer.at;
                timers.delete(id);
                timer.callback();
                for (let step = 0; step < 10; step++) await Promise.resolve();
            }
            now = end;
            for (let step = 0; step < 10; step++) await Promise.resolve();
        },
        failCompletion(message) { completionError = message; },
        holdCompletion() { holdCompletion = true; },
        releaseCompletion() { releaseCompletion(); },
        holdTaskLoad() { holdTaskLoad = true; },
        releaseTaskLoad() { releaseTaskLoad(); },
        failColor(message) { colorError = message; },
        holdColor() { holdColor = true; },
        releaseColor() { releaseColor(); },
        nextRecurringTask(task) { nextRecurringTask = task; },
        reduceMotion() { reducedMotion = true; },
        async saveProgress(id, progress) {
            const item = this.item(id);
            item.querySelector('.progress-range').value = progress;
            await item.querySelectorAll('button').find(button => button.textContent === '進捗を保存').fire('click');
        },
        confirm() { return element('#completion-form').fire('submit'); },
        async pointer(name, details = {}) {
            await documentListeners.get(name)?.({ pointerId: 1, preventDefault() {}, stopPropagation() {}, ...details });
            await this.advance(0);
        },
    };
}

module.exports = { createScreen };
