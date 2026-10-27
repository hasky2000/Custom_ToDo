const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const SettingsSaveQueue = require('../src/public/assets/settings-save-queue.js');
const paletteFormat = require('../src/public/assets/palette-format.js');
const catalog = require('../src/app/color_templates.json');
const templates = catalog.templates.filter(template => template.id !== catalog.default_id);
const clone = value => JSON.parse(JSON.stringify(value));

// DOMとAPIだけを置き換え、画面のイベントと実際の保存キューを組み合わせて確認する。
async function createSettingsScreen() {
    const elements = new Map();
    function createElement() {
        const listeners = new Map();
        return {
            value: '', textContent: '', dataset: {}, type: 'text',
            classList: { toggle() {} },
            append() {}, setAttribute() {}, setCustomValidity() {},
            checkValidity() { return true; },
            querySelectorAll() { return []; },
            querySelector(selector) {
                this.children ??= new Map();
                if (!this.children.has(selector)) this.children.set(selector, createElement());
                return this.children.get(selector);
            },
            addEventListener(name, callback) { listeners.set(name, callback); },
            emit(name) { return listeners.get(name)?.({ preventDefault() {} }); },
        };
    }
    function element(selector) {
        if (!elements.has(selector)) elements.set(selector, createElement());
        return elements.get(selector);
    }
    element('#color-template-data').textContent = JSON.stringify(catalog);
    for (const id of ['input-border-width', 'card-border-width', 'button-border-width']) element(`#${id}`).type = 'number';
    for (const id of ['appearance-blur', 'appearance-shadow']) element(`#${id}`).type = 'checkbox';
    const buttons = catalog.templates.map(template => {
        const button = createElement();
        button.dataset.colorTemplate = template.id;
        return button;
    });
    const properties = new Map();
    const storage = new Map();
    let stored = { colors: {}, appearance: { font: 'serif', button_border_width: 3 } };
    let nextSaveGate = null;
    let failNextSave = false;
    const sent = [];
    const context = vm.createContext({
        document: {
            querySelector: element,
            querySelectorAll(selector) { return selector === '[data-color-template]' ? buttons : []; },
            createElement,
            documentElement: {
                dataset: {},
                style: {
                    setProperty(key, value) { properties.set(key, value); },
                    removeProperty(key) { properties.delete(key); },
                },
            },
        },
        window: { addEventListener() {} },
        localStorage: { getItem(key) { return storage.get(key) ?? null; }, setItem(key, value) { storage.set(key, value); } },
        paletteFormat, SettingsSaveQueue,
        async requestJson(_url, options = {}) {
            if (options.method !== 'PUT') return { data: clone(stored) };
            const settings = JSON.parse(options.body);
            sent.push(settings);
            if (failNextSave) {
                failNextSave = false;
                throw new Error('保存に失敗しました。');
            }
            if (nextSaveGate) {
                const gate = nextSaveGate;
                nextSaveGate = null;
                await gate;
            }
            stored = clone(settings);
            return { data: clone(stored) };
        },
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../src/public/assets/colors.js'), 'utf8'), context);
    await vm.runInContext('loadUserColors(1)', context);
    return {
        select(template) { buttons.find(button => button.dataset.colorTemplate === template.id).emit('click'); },
        save() { element('#colors-form').emit('submit'); },
        cancel() { element('#cancel-colors').emit('click'); },
        flush() { return vm.runInContext('settingsSaveQueue.flush()', context); },
        holdNextSave() {
            let release;
            nextSaveGate = new Promise(resolve => { release = resolve; });
            return release;
        },
        failNextSave() { failNextSave = true; },
        state() {
            return {
                stored: clone(stored),
                draft: clone(vm.runInContext('({ colors: draftColors, appearance: draftAppearance })', context)),
                displayedPrimary: properties.get('--theme-primary'),
                cached: JSON.parse(storage.get('custom-todo-settings-v1:1')),
                sent: clone(sent),
            };
        },
        dispose() { vm.runInContext('resetUserColors()', context); },
    };
}

function assertRestored(screen, template) {
    const state = screen.state();
    const expected = { colors: template.colors, appearance: { font: 'serif', button_border_width: 3 } };
    assert.deepEqual(state.draft, expected);
    assert.deepEqual(state.stored, expected);
    assert.equal(state.displayedPrimary, template.colors.primary);
    assert.deepEqual(state.cached, { ...expected, pending: false });
}

test('テンプレートを保存後、別のテンプレートを取り消すと保存済みの配色に戻る', async () => {
    const screen = await createSettingsScreen();
    try {
        screen.select(templates[0]);
        await screen.flush();
        screen.save();
        await screen.flush();
        screen.select(templates[1]);
        await screen.flush();
        screen.cancel();
        await screen.flush();
        assertRestored(screen, templates[0]);
    } finally { screen.dispose(); }
});

test('複数のテンプレートを自動保存した後の取り消しは、直前に保存した配色に戻る', async () => {
    const screen = await createSettingsScreen();
    try {
        for (const template of templates) {
            screen.select(template);
            await screen.flush();
        }
        screen.cancel();
        await screen.flush();
        assertRestored(screen, templates.at(-2));
    } finally { screen.dispose(); }
});

test('別のテンプレートの保存中に取り消しても、最後に変更前の配色を保存する', async () => {
    const screen = await createSettingsScreen();
    try {
        screen.select(templates[0]);
        await screen.flush();
        const release = screen.holdNextSave();
        screen.select(templates[1]);
        screen.cancel();
        assert.deepEqual(screen.state().draft.colors, templates[0].colors);
        release();
        await screen.flush();
        assertRestored(screen, templates[0]);
        assert.deepEqual(screen.state().sent.map(settings => settings.colors.primary), [templates[0], templates[1], templates[0]].map(template => template.colors.primary));
    } finally { screen.dispose(); }
});

test('別のテンプレートの保存に失敗しても、取り消すと直前の保存済み配色に戻る', async () => {
    const screen = await createSettingsScreen();
    try {
        screen.select(templates[0]);
        await screen.flush();
        screen.failNextSave();
        screen.select(templates[1]);
        await screen.flush();
        screen.cancel();
        await screen.flush();
        assertRestored(screen, templates[0]);
    } finally { screen.dispose(); }
});

test('初期配色への変更も取り消すと直前の保存済みテンプレートに戻る', async () => {
    const screen = await createSettingsScreen();
    try {
        screen.select(templates[0]);
        await screen.flush();
        screen.select(catalog.templates.find(template => template.id === catalog.default_id));
        await screen.flush();
        screen.cancel();
        await screen.flush();
        assertRestored(screen, templates[0]);
    } finally { screen.dispose(); }
});
