const { test } = require('node:test');
const assert = require('node:assert/strict');
const paletteFormat = require('../src/public/assets/palette-format.js');
const SettingsSaveQueue = require('../src/public/assets/settings-save-queue.js');

// 共有文字列と今回追加した自動保存だけを確認する。
const defaults = {
    page: '#EEF0FF', surface: '#FFFFFF', header: '#90A9FD', card: '#FFFFFF', input: '#FFFFFF',
    primary: '#90A9FD', primary_text: '#5059A6', secondary: '#EEF0FF', secondary_text: '#5059A6',
    text: '#5059A6', border: '#EEF0FF', progress: '#90A9FD', navigation: '#FFFFFF', navigation_active: '#EEF0FF',
};

test('初期色を含めてエクスポートし、別ユーザーでも同じ配色を読み込める', () => {
    const text = paletteFormat.export({ primary: '#123abc', page: '#CCEEFF' }, defaults);
    const imported = paletteFormat.import(text, defaults);
    assert.deepEqual(imported, { ...defaults, primary: '#123ABC', page: '#CCEEFF' });
    assert.equal(Object.keys(imported).length, 14);
    assert.deepEqual(Object.keys(JSON.parse(text)), ['format', 'version', 'colors']);
});

test('初期色と空のカスタム色も共有できる', () => {
    assert.deepEqual(paletteFormat.import(paletteFormat.export({}, defaults), defaults), defaults);
});

for (const [name, input] of [
    ['不正な文字列', 'not json'],
    ['途中で切れた文字列', '{"format":'],
    ['異なる共有形式', JSON.stringify({ format: 'other', version: 1, colors: defaults })],
    ['未対応バージョン', JSON.stringify({ format: 'custom-todo-palette', version: 2, colors: defaults })],
    ['色の不足', JSON.stringify({ format: 'custom-todo-palette', version: 1, colors: { primary: '#123456' } })],
    ['CSSやURLを含む色', JSON.stringify({ format: 'custom-todo-palette', version: 1, colors: { ...defaults, primary: 'url(https://example.invalid)' } })],
    ['アカウント情報を含む色データ', JSON.stringify({ format: 'custom-todo-palette', version: 1, colors: { ...defaults, password: '#123456' } })],
    ['配列形式の色', JSON.stringify({ format: 'custom-todo-palette', version: 1, colors: [] })],
    ['長すぎる文字列', ' '.repeat(16385)],
    ['null', 'null'],
]) {
    test(`${name}は取り込まず、現在の配色を変更しない`, () => {
        const before = { ...defaults };
        assert.throws(() => paletteFormat.import(input, before));
        assert.deepEqual(before, defaults);
    });
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

const settings = (color) => ({ colors: { primary: color }, appearance: { font: 'serif' } });

test('保存中に連続編集しても、最後の色を最後に保存する', async () => {
    const firstRequest = deferred();
    const sent = [];
    const saved = [];
    const queue = new SettingsSaveQueue({
        delay: 10000,
        send: async (value) => {
            sent.push(value);
            if (sent.length === 1) await firstRequest.promise;
            return value;
        },
        onSaved: (value, latest) => saved.push({ color: value.colors.primary, latest }),
    });
    try {
        const running = queue.schedule(settings('#111111'), 'first', true);
        queue.schedule(settings('#222222'), 'second');
        queue.schedule(settings('#333333'), 'last');
        firstRequest.resolve();
        await running;
        assert.deepEqual(sent.map(value => value.colors.primary), ['#111111', '#333333']);
        assert.deepEqual(saved, [{ color: '#111111', latest: false }, { color: '#333333', latest: true }]);
        assert.equal(sent[1].appearance.font, 'serif');
    } finally { queue.dispose(); }
});

test('保存前にリロード・離脱する場合もflushで最新の変更を送信する', async () => {
    const sent = [];
    const queue = new SettingsSaveQueue({ delay: 10000, send: async (value) => { sent.push(value); return value; } });
    try {
        const draft = settings('#123456');
        queue.schedule(draft, 'saved');
        draft.colors.primary = '#654321';
        await queue.flush();
        assert.equal(sent[0].colors.primary, '#123456');
    } finally { queue.dispose(); }
});

test('ログアウト後は前のユーザーの応答を反映しない', async () => {
    const request = deferred();
    const saved = [];
    const queue = new SettingsSaveQueue({ send: () => request.promise, onSaved: value => saved.push(value) });
    const running = queue.schedule(settings('#123456'), 'saved', true);
    queue.dispose();
    request.resolve(settings('#123456'));
    await running;
    assert.deepEqual(saved, []);
});

test('保存に失敗しても、再保存で最新の設定を送信できる', async () => {
    const errors = [];
    const saved = [];
    let fail = true;
    const queue = new SettingsSaveQueue({
        send: async value => { if (fail) throw new Error('offline'); return value; },
        onError: (error, latest) => errors.push({ message: error.message, latest }),
        onSaved: value => saved.push(value),
    });
    try {
        await queue.schedule(settings('#111111'), 'saved', true);
        fail = false;
        await queue.schedule(settings('#222222'), 'retried', true);
        assert.deepEqual(errors, [{ message: 'offline', latest: true }]);
        assert.equal(saved[0].colors.primary, '#222222');
    } finally { queue.dispose(); }
});
