const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createScreen } = require('./helpers/task-screen.cjs');
const palette = require('../src/app/task_colors.json');

function colorButton(screen, id, color) {
    return screen.item(id).querySelector(`[data-color="${color}"]`);
}

test('小さい色ボタンから16色のパレットを開閉できる', async () => {
    const screen = await createScreen();
    assert.equal(palette.length, 16);
    assert.equal(new Set(palette.map(color => color.value)).size, 16);
    const options = screen.item(1).querySelector('.task-color-options');
    const trigger = screen.item(1).querySelector('.task-color-trigger');
    const actions = screen.item(1).querySelector('.task-actions');
    assert.equal(actions.children[0].textContent, '編集');
    assert.equal(actions.children[1].textContent, '削除');
    assert.equal(actions.children[2], trigger.closest('.task-color-section'));
    assert.equal(options.hidden, true);
    assert.equal(screen.item(1).querySelectorAll('.task-color-swatch').length, 16);
    await trigger.fire('click');
    assert.equal(options.hidden, false);
    assert.equal(trigger['aria-expanded'], 'true');
    await trigger.fire('click');
    assert.equal(options.hidden, true);
});

test('選んだタスクの左端にライン色を保存し、背景・文字・未保存の進捗を保つ', async () => {
    const screen = await createScreen();
    const item = screen.item(1);
    const details = item.querySelector('details');
    details.open = true;
    item.querySelector('.progress-range').value = 77;
    await colorButton(screen, 1, '#FECACA').fire('click');
    assert.deepEqual(JSON.parse(screen.requests[1].body), { id: 1, background_color: '#FECACA' });
    assert.equal(details.style['--task-line-color'], '#FECACA');
    assert.equal(details.style['--theme-text'], undefined);
    assert.equal(details.style['--theme-card'], undefined);
    assert.equal(details.classList.contains('task-colored-card'), true);
    assert.equal(details.open, true);
    assert.equal(item.querySelector('.progress-range').value, 77);
    assert.equal(screen.item(2).querySelector('details').style['--task-line-color'], undefined);
    assert.equal(colorButton(screen, 1, '#FECACA')['aria-pressed'], 'true');
    assert.equal(item.querySelector('.task-color-options').hidden, true);
    await screen.run('getTasks()');
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], '#FECACA');
});

test('標準に戻すと左端の色のラインを消す', async () => {
    const screen = await createScreen();
    await colorButton(screen, 1, '#BFDBFE').fire('click');
    await screen.item(1).querySelector('.task-color-reset').fire('click');
    assert.deepEqual(JSON.parse(screen.requests.at(-1).body), { id: 1, background_color: null });
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], undefined);
    assert.equal(screen.item(1).querySelector('details').style['--theme-text'], undefined);
    assert.equal(screen.item(1).querySelector('details').classList.contains('task-colored-card'), false);
    await screen.run('getTasks()');
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], undefined);
});

test('色変更前に取得した古い一覧が遅れて届いても、保存した色を失わない', async () => {
    const screen = await createScreen();
    screen.holdTaskLoad();
    const reload = screen.run('getTasks()');
    await colorButton(screen, 1, '#FECACA').fire('click');
    screen.releaseTaskLoad();
    await reload;
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], '#FECACA');
});

test('保存失敗時は元の色を保ち、理由を表示して再試行できる', async () => {
    const screen = await createScreen();
    await screen.item(1).querySelector('.task-color-trigger').fire('click');
    screen.failColor('色を保存できませんでした。');
    await colorButton(screen, 1, '#BBF7D0').fire('click');
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], undefined);
    assert.equal(screen.item(1).querySelector('.task-color-options').hidden, false);
    assert.equal(screen.item(1).querySelector('.task-color-message').textContent, '色を保存できませんでした。');
    assert.equal(colorButton(screen, 1, '#BBF7D0').disabled, false);
    screen.failColor(null);
    await colorButton(screen, 1, '#BBF7D0').fire('click');
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], '#BBF7D0');
});

test('保存中の連打ではAPIを重複して呼ばず、完了猶予中も色を変更しない', async () => {
    const screen = await createScreen();
    screen.holdColor();
    const pending = colorButton(screen, 1, '#FBCFE8').fire('click');
    await colorButton(screen, 1, '#BFDBFE').fire('click');
    assert.equal(screen.requests.length, 2);
    assert.equal(screen.item(1).querySelector('.task-color-trigger').disabled, true);
    screen.releaseColor();
    await pending;
    assert.equal(screen.item(1).querySelector('details').style['--task-line-color'], '#FBCFE8');
    await screen.check(1, true);
    await colorButton(screen, 1, '#BFDBFE').fire('click');
    assert.equal(screen.requests.length, 2);
    assert.equal(screen.item(1).querySelector('.task-color-trigger').disabled, true);
});

test('ログアウト後の保存応答は、以前のタスクの色を画面に反映しない', async () => {
    const screen = await createScreen();
    const card = screen.item(1).querySelector('details');
    screen.holdColor();
    const pending = colorButton(screen, 1, '#FECACA').fire('click');
    screen.run('showAuthView()');
    screen.releaseColor();
    await pending;
    assert.equal(card.style['--task-line-color'], undefined);
    assert.equal(screen.run('tasks.length'), 0);
});

test('アーカイブのカードでも左端のライン色を保持する', async () => {
    const screen = await createScreen();
    const card = screen.run(`createCompletedTaskElement({
        id: 10, title: '完了したタスク', task_type: 'unscheduled',
        background_color: '#DDD6FE', completed_at: '2026-11-10T12:00:00+09:00'
    })`);
    assert.equal(card.style['--task-line-color'], '#DDD6FE');
    assert.equal(card.style['--theme-text'], undefined);
    assert.equal(card.style['--theme-card'], undefined);
});

test('Escapeでパレットを閉じ、同じ色を選び直しても再保存しない', async () => {
    const screen = await createScreen();
    await colorButton(screen, 1, '#FEF08A').fire('click');
    await colorButton(screen, 1, '#FEF08A').fire('click');
    assert.equal(screen.requests.length, 2);
    await screen.item(1).querySelector('.task-color-trigger').fire('click');
    await screen.item(1).querySelector('.task-color-section').fire('keydown', { key: 'Escape' });
    assert.equal(screen.item(1).querySelector('.task-color-options').hidden, true);
});
