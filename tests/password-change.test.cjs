const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// APIの検証はapi.phpで行い、ここでは画面遷移と送信中のフォームの扱いを確認する。
function createScreen() {
    const elements = new Map();
    function element(selector) {
        if (elements.has(selector)) return elements.get(selector);
        const classes = new Set();
        const listeners = new Map();
        const node = {
            textContent: '', value: '', disabled: false, dataset: {}, validity: { badInput: false },
            classList: {
                add(name) { classes.add(name); },
                remove(name) { classes.delete(name); },
                contains(name) { return classes.has(name); },
                toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); },
            },
            addEventListener(name, callback) {
                if (!listeners.has(name)) listeners.set(name, []);
                listeners.get(name).push(callback);
            },
            setAttribute(name, value) { this[name] = value; },
            removeAttribute(name) { delete this[name]; },
            querySelector(child) { return element(`${selector} ${child}`); },
            elements: { namedItem(name) { return element(`${selector} [name="${name}"]`); } },
            reset() {
                if (selector === '#password-change-form') {
                    element('#current-password').value = '';
                    element('#new-password').value = '';
                    element('#new-password-confirmation').value = '';
                }
            },
            reportValidity() { return true; },
            checkValidity() { return true; },
            setCustomValidity(message) { this.validationMessage = message; },
            async submit() {
                for (const callback of listeners.get('submit') ?? []) await callback({ preventDefault() {} });
            },
            input(value) {
                this.value = value;
                for (const callback of listeners.get('input') ?? []) callback();
            },
        };
        elements.set(selector, node);
        return node;
    }

    const requests = [];
    let respond;
    const context = vm.createContext({
        document: { querySelector: element, querySelectorAll() { return []; }, addEventListener() {} },
        window: { location: { hash: '#account' }, addEventListener() {}, scrollTo() {} },
        setInterval() {},
        taskSchedule: require('../src/public/assets/task-schedule.js'),
        FormData: class {
            *[Symbol.iterator]() {
                yield ['current_password', element('#current-password').value];
                yield ['new_password', element('#new-password').value];
                yield ['new_password_confirmation', element('#new-password-confirmation').value];
            }
        },
        fetch(url, options) {
            requests.push({ url, ...options });
            return new Promise(resolve => { respond = resolve; });
        },
    });
    const source = fs.readFileSync(path.join(__dirname, '../src/public/assets/app.js'), 'utf8');
    // 自動ログインの通信だけを起動せず、実際のイベントハンドラーを読み込む。
    vm.runInContext(source.slice(0, source.lastIndexOf('\ninitialize().catch(')), context);

    return {
        element, requests,
        async open(route) {
            context.window.location.hash = `#${route}`;
            await vm.runInContext('activateCurrentPage()', context);
        },
        enterPasswords() {
            element('#current-password').value = 'current-test-password';
            element('#new-password').value = 'new-test-password';
            element('#new-password-confirmation').value = 'new-test-password';
        },
        submit() { return element('#password-change-form').submit(); },
        respond(ok, result) { respond({ ok, async json() { return result; } }); },
    };
}

test('変更画面ではアカウントへの戻るリンクを表示し、他の画面を隠す', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    assert.equal(screen.element('#page-title').textContent, 'パスワード変更');
    assert.equal(screen.element('#header-back').href, '#account');
    assert.equal(screen.element('#header-back')['aria-label'], 'アカウントに戻る');
    assert.equal(screen.element('#password-change-page').classList.contains('hidden'), false);
    for (const page of ['todo', 'my', 'archive', 'settings', 'settings-detail', 'account']) {
        assert.equal(screen.element(`#${page}-page`).classList.contains('hidden'), true);
    }
    await screen.open('account');
    assert.equal(screen.element('#account-page').classList.contains('hidden'), false);
    assert.equal(screen.element('#password-change-page').classList.contains('hidden'), true);
});

test('変更に成功すると入力を消去し、完了メッセージを表示する', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    const pending = screen.submit();
    assert.equal(screen.requests[0].url, '/api/change_password.php');
    assert.equal(screen.requests[0].method, 'POST');
    assert.equal(screen.requests[0].headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(screen.requests[0].body), {
        current_password: 'current-test-password', new_password: 'new-test-password', new_password_confirmation: 'new-test-password',
    });
    assert.equal(screen.element('#password-change-form [type="submit"]').disabled, true);
    screen.respond(true, { data: { password_changed: true } });
    await pending;
    assert.equal(screen.element('#current-password').value, '');
    assert.equal(screen.element('#new-password').value, '');
    assert.equal(screen.element('#new-password-confirmation').value, '');
    assert.match(screen.element('#password-change-message').textContent, /パスワードを変更しました/);
    assert.equal(screen.element('#password-change-form [type="submit"]').disabled, false);
});

test('現在のパスワードが違う場合は理由を表示し、修正して再送信できる', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    const pending = screen.submit();
    screen.respond(false, { error: '現在のパスワードが正しくありません。' });
    await pending;
    assert.equal(screen.element('#password-change-message').textContent, '現在のパスワードが正しくありません。');
    assert.equal(screen.element('#new-password').value, 'new-test-password');
    assert.equal(screen.element('#password-change-form [type="submit"]').disabled, false);
    const retry = screen.submit();
    screen.respond(true, { data: { password_changed: true } });
    await retry;
    assert.equal(screen.requests.length, 2);
    assert.match(screen.element('#password-change-message').textContent, /パスワードを変更しました/);
});

test('送信中に再送信してもAPIを重複して呼ばない', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    const pending = screen.submit();
    await screen.submit();
    assert.equal(screen.requests.length, 1);
    screen.respond(true, { data: { password_changed: true } });
    await pending;
});

test('画面を離れるとパスワードを消去し、遅れて届いた成功・失敗を表示しない', async () => {
    for (const ok of [true, false]) {
        const screen = createScreen();
        await screen.open('account/password');
        screen.enterPasswords();
        const pending = screen.submit();
        await screen.open('account');
        assert.equal(screen.element('#current-password').value, '');
        assert.equal(screen.element('#new-password').value, '');
        assert.equal(screen.element('#new-password-confirmation').value, '');
        await screen.open('account/password');
        screen.respond(ok, ok ? { data: { password_changed: true } } : { error: '変更できませんでした。' });
        await pending;
        assert.equal(screen.element('#password-change-message').textContent, '');
        assert.equal(screen.element('#password-change-form [type="submit"]').disabled, false);
    }
});

test('現在と同じパスワードは送信せず、異なるパスワードに修正すると送信できる', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    screen.element('#new-password').input('current-test-password');
    screen.element('#new-password-confirmation').input('current-test-password');
    await screen.submit();
    assert.equal(screen.requests.length, 0);
    assert.equal(screen.element('#password-change-message').textContent, '新しいパスワードは現在のパスワードと異なるものを入力してください。');
    assert.equal(screen.element('#new-password').validationMessage, '新しいパスワードは現在のパスワードと異なるものを入力してください。');

    screen.element('#new-password').input('different-test-password');
    screen.element('#new-password-confirmation').input('different-test-password');
    assert.equal(screen.element('#new-password').validationMessage, '');
    const pending = screen.submit();
    screen.respond(true, { data: { password_changed: true } });
    await pending;
    assert.equal(screen.requests.length, 1);
});

test('現在のパスワードを修正した場合も再照合し、画面を離れると同一判定のエラーを消去する', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    screen.element('#current-password').input('new-test-password');
    assert.match(screen.element('#new-password').validationMessage, /現在のパスワードと異なる/);
    screen.element('#current-password').input('current-test-password');
    assert.equal(screen.element('#new-password').validationMessage, '');
    screen.element('#current-password').input('new-test-password');
    await screen.open('account');
    await screen.open('account/password');
    assert.equal(screen.element('#new-password').validationMessage, '');
});

test('ブラウザーの入力検証に失敗した場合は送信しない', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.element('#password-change-form').reportValidity = () => false;
    await screen.submit();
    assert.equal(screen.requests.length, 0);
});

test('確認用パスワードが一致しない場合は送信せず、一致するよう修正すると送信できる', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    screen.element('#new-password-confirmation').input('different-password');
    await screen.submit();
    assert.equal(screen.requests.length, 0);
    assert.equal(screen.element('#password-change-message').textContent, '確認用パスワードが一致しません。');
    assert.equal(screen.element('#new-password-confirmation').validationMessage, '確認用パスワードが一致しません。');
    screen.element('#new-password-confirmation').input('new-test-password');
    assert.equal(screen.element('#new-password-confirmation').validationMessage, '');
    const pending = screen.submit();
    screen.respond(true, { data: { password_changed: true } });
    await pending;
    assert.equal(screen.requests.length, 1);
});

test('元の入力を変更した場合も再照合し、画面を開き直すと確認欄のエラーを消去する', async () => {
    const screen = createScreen();
    await screen.open('account/password');
    screen.enterPasswords();
    screen.element('#new-password').input('another-test-password');
    assert.equal(screen.element('#new-password-confirmation').validationMessage, '確認用パスワードが一致しません。');
    await screen.submit();
    assert.equal(screen.requests.length, 0);
    await screen.open('account');
    await screen.open('account/password');
    assert.equal(screen.element('#new-password-confirmation').validationMessage, '');
    assert.equal(screen.element('#new-password-confirmation').value, '');
    screen.enterPasswords();
    const pending = screen.submit();
    screen.respond(true, { data: { password_changed: true } });
    await pending;
    assert.equal(screen.requests.length, 1);
});
