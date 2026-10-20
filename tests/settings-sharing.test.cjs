const { test } = require('node:test');
const assert = require('node:assert/strict');
const paletteFormat = require('../src/public/assets/palette-format.js');
const SettingsSaveQueue = require('../src/public/assets/settings-save-queue.js');

// 共有文字列と今回追加した自動保存だけを確認する。
const templates = require('../src/app/color_templates.json');
const defaults = templates.templates.find(template => template.id === templates.default_id).colors;

test('サーバーの配色テンプレートは複数あり、初期配色を一意に選べる', () => {
    assert.ok(templates.templates.length >= 2);
    assert.equal(new Set(templates.templates.map(template => template.id)).size, templates.templates.length);
    assert.equal(templates.templates.filter(template => template.id === templates.default_id).length, 1);
    assert.equal(Object.keys(defaults).length, 14);
});

for (const template of templates.templates) {
    test(`${template.name}は全項目のカラーコードとRGB差制限を満たし、共有できる`, () => {
        assert.ok(template.name.trim());
        assert.deepEqual(paletteFormat.normalizeColors(template.colors, defaults, true), template.colors);
        assert.deepEqual(paletteFormat.import(paletteFormat.export(template.colors, defaults), defaults), template.colors);
    });
}

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

const textBackgrounds = {
    text: ['page', 'surface', 'header', 'card', 'input', 'secondary', 'navigation', 'navigation_active'],
    primary_text: ['primary'],
    secondary_text: ['secondary'],
};
for (const [textKey, backgrounds] of Object.entries(textBackgrounds)) {
    for (const background of backgrounds) {
        test(`${textKey}と${background}が同色になる配色は大文字・小文字が異なっても拒否する`, () => {
            assert.throws(() => paletteFormat.normalizeColors({ [textKey]: '#aabbcc', [background]: '#AABBCC' }, defaults), /文字色と背景色/);
        });
        test(`${textKey}と${background}のRGB差の絶対値合計が9なら拒否し10なら許可する`, () => {
            for (const [textColor, backgroundColor] of [['#112233', '#122637'], ['#122637', '#112233']]) {
                assert.throws(() => paletteFormat.normalizeColors({ [textKey]: textColor, [background]: backgroundColor }, defaults), /10以上/);
            }
            for (const [textColor, backgroundColor] of [['#112233', '#122638'], ['#122638', '#112233']]) {
                assert.deepEqual(paletteFormat.normalizeColors({ [textKey]: textColor, [background]: backgroundColor }, defaults), { [textKey]: textColor, [background]: backgroundColor });
            }
        });
    }
}

test('RGB差は符号で相殺せず、各チャンネルの差の絶対値を合計する', () => {
    assert.equal(paletteFormat.colorDistance('#102030', '#122333'), 8);
    assert.equal(paletteFormat.colorDistance('#122030', '#102333'), 8);
    assert.equal(paletteFormat.colorDistance('#000000', '#FFFFFF'), 765);
});

test('省略された初期背景・文字色とも比較する', () => {
    for (const colors of [{ text: '#ffffff' }, { primary: '#5059a6' }, { primary_text: '#90a9fd' }, { secondary_text: '#eef0ff' }]) {
        assert.throws(() => paletteFormat.normalizeColors(colors, defaults), /文字色と背景色/);
    }
});

test('全文字・背景の同色化をインポート・エクスポートの両方で拒否する', () => {
    const invisible = Object.fromEntries(Object.keys(defaults).map(key => [key, '#123456']));
    assert.throws(() => paletteFormat.export(invisible, defaults), /文字色と背景色/);
    const shared = JSON.stringify({ format: 'custom-todo-palette', version: 1, colors: invisible });
    assert.throws(() => paletteFormat.import(shared, defaults), /文字色と背景色/);
});

test('背景同士は同じ色に揃えられ、文字色が異なれば共有できる', () => {
    const colors = Object.fromEntries(Object.keys(defaults).map(key => [key, key.includes('text') ? '#FFFFFF' : '#222222']));
    assert.deepEqual(paletteFormat.import(paletteFormat.export(colors, defaults), defaults), colors);
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
