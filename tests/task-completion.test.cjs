const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createScreen } = require('./helpers/task-screen.cjs');

test('2499msまでは取り消せて2500msで保存し、200msの消去トランジション後に一覧から除く', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    const item = screen.item(1);
    assert.equal(item.classList.contains('task-completion-pending'), true);
    assert.equal(item.querySelector('input[type="checkbox"]').checked, true);
    assert.match(item.querySelector('input[type="checkbox"]')['aria-label'], /完了を取り消す/);
    assert.equal(item.querySelector('.progress-range').disabled, true);
    await screen.advance(2499);
    assert.equal(screen.item(1), item);
    assert.equal(item.querySelector('input[type="checkbox"]').disabled, false);
    assert.equal(screen.requests.length, 1);
    await screen.advance(1);
    assert.equal(item.classList.contains('task-removing'), true);
    assert.equal(item.style.height, '0px');
    assert.equal(item.querySelector('input[type="checkbox"]').disabled, true);
    await screen.advance(199);
    assert.equal(screen.item(1), item);
    await screen.advance(1);
    assert.equal(screen.item(1), null);
    assert.equal(screen.element('#task-count').textContent, '2件');
    assert.equal(screen.requests[1].url, '/api/complete_task.php');
    assert.deepEqual(JSON.parse(screen.requests[1].body), { id: 1 });
});

test('チェック解除で通信せず元の進捗と位置に戻り、再チェックで2.5秒を数え直す', async () => {
    const screen = await createScreen();
    await screen.check(2, true);
    await screen.advance(2499);
    await screen.check(2, false);
    assert.equal(screen.item(2).classList.contains('task-completion-pending'), false);
    assert.equal(screen.item(2).querySelector('.progress-range').value, 40);
    assert.equal(screen.item(2).querySelector('.progress-range').disabled, false);
    assert.equal(screen.run('taskOrder().join(",")'), '1,2,3');
    await screen.advance(5000);
    assert.equal(screen.requests.length, 1);
    await screen.check(2, true);
    await screen.advance(2499);
    assert.ok(screen.item(2));
    await screen.advance(201);
    assert.equal(screen.item(2), null);
});

test('進捗100%では確認ポップアップを開き、確定前は保存やトランジションを開始しない', async () => {
    const screen = await createScreen();
    const item = screen.item(3);
    await screen.saveProgress(3, 100);
    assert.equal(screen.element('#completion-dialog').open, true);
    assert.equal(screen.element('#completion-task-title').textContent, 'タスク3');
    assert.equal(item.querySelector('input[type="checkbox"]').checked, false);
    assert.equal(item.classList.contains('task-completion-pending'), false);
    assert.equal(item.classList.contains('task-removing'), false);
    await screen.advance(5000);
    assert.equal(screen.requests.length, 1);
    await screen.element('#cancel-completion').fire('click');
    assert.equal(screen.element('#completion-dialog').open, false);
    assert.equal(item.querySelector('output').textContent, '60%');
    assert.equal(screen.requests.length, 1);
});

test('ポップアップで確定すると猶予や消去トランジションなしにホームから消す', async () => {
    const screen = await createScreen();
    const item = screen.item(3);
    await screen.saveProgress(3, 100);
    await screen.confirm();
    assert.equal(screen.item(3), null);
    assert.equal(screen.element('#completion-dialog').open, false);
    assert.equal(item.classList.contains('task-completion-pending'), false);
    assert.equal(item.classList.contains('task-removing'), false);
    assert.equal(screen.element('#task-count').textContent, '2件');
    assert.equal(screen.requests[1].url, '/api/complete_task.php');
    await screen.advance(5000);
    assert.equal(screen.requests.length, 2);
});

test('ポップアップはEscapeや背景クリックでも取り消せる', async () => {
    for (const event of ['cancel', 'click']) {
        const screen = await createScreen();
        await screen.saveProgress(3, 100);
        await screen.element('#completion-dialog').fire(event);
        assert.equal(screen.element('#completion-dialog').open, false);
        assert.equal(screen.item(3).querySelector('output').textContent, '60%');
        assert.equal(screen.requests.length, 1);
    }
});

test('ポップアップの送信は重複せず、失敗時はエラーを表示して再試行できる', async () => {
    const screen = await createScreen();
    screen.holdCompletion();
    screen.failCompletion('TODOを完了できませんでした。');
    await screen.saveProgress(3, 100);
    const first = screen.confirm();
    await screen.confirm();
    assert.equal(screen.requests.length, 2);
    assert.equal(screen.element('#confirm-completion').disabled, true);
    assert.equal(screen.element('#cancel-completion').disabled, true);
    await screen.element('#completion-dialog').fire('cancel');
    assert.equal(screen.element('#completion-dialog').open, true);
    screen.releaseCompletion();
    await first;
    assert.equal(screen.element('#completion-dialog').open, true);
    assert.equal(screen.element('#completion-message').textContent, 'TODOを完了できませんでした。');
    assert.equal(screen.element('#confirm-completion').disabled, false);
    assert.equal(screen.item(3).querySelector('output').textContent, '60%');
    screen.failCompletion(null);
    const retry = screen.confirm();
    screen.releaseCompletion();
    await retry;
    assert.equal(screen.item(3), null);
    assert.equal(screen.element('#completion-dialog').open, false);
});

test('一覧の再描画後も猶予を維持し、複数タスクのタイマーを個別に扱う', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    await screen.advance(1000);
    await screen.check(2, true);
    await screen.run('getTasks()');
    assert.equal(screen.item(1).querySelector('input[type="checkbox"]').checked, true);
    assert.equal(screen.item(2).classList.contains('task-completion-pending'), true);
    await screen.advance(1700);
    assert.equal(screen.item(1), null);
    assert.ok(screen.item(2));
    await screen.check(2, false);
    await screen.advance(1000);
    assert.ok(screen.item(2));
    assert.equal(screen.requests.filter(request => request.url === '/api/complete_task.php').length, 1);
});

test('保存中はチェックを無効にし、API失敗時は元の状態で再操作できる', async () => {
    const screen = await createScreen();
    screen.holdCompletion();
    screen.failCompletion('TODOを完了できませんでした。');
    await screen.check(1, true);
    await screen.advance(2500);
    assert.equal(screen.item(1).querySelector('input[type="checkbox"]').disabled, true);
    screen.releaseCompletion();
    await screen.advance(0);
    assert.equal(screen.item(1).querySelector('input[type="checkbox"]').checked, false);
    assert.equal(screen.item(1).querySelector('input[type="checkbox"]').disabled, false);
    assert.equal(screen.item(1).querySelector('output').textContent, '20%');
    assert.equal(screen.element('#message').textContent, 'TODOを完了できませんでした。');
    assert.equal(screen.item(2).querySelector('summary').dataset.dragEnabled, 'true');
});

test('完了確定前に取得した一覧が遅れて届いても、完了済みタスクを再表示しない', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    screen.holdTaskLoad();
    const reload = screen.run('getTasks()');
    await screen.advance(2700);
    assert.equal(screen.item(1), null);
    screen.releaseTaskLoad();
    await reload;
    assert.equal(screen.item(1), null);
    assert.equal(screen.element('#task-count').textContent, '2件');
});

test('完了猶予中は並べ替えを止め、解除後は再び使える', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    assert.equal(screen.item(2).querySelector('summary').dataset.dragEnabled, 'false');
    await screen.check(1, false);
    assert.equal(screen.item(2).querySelector('summary').dataset.dragEnabled, 'true');
});

test('動きを減らす設定では2.5秒後にアニメーションを省略して消す', async () => {
    const screen = await createScreen();
    screen.reduceMotion();
    await screen.check(1, true);
    await screen.advance(2500);
    assert.equal(screen.item(1), null);
});

test('トランジションの終了イベント後にタイマーが残って別のタスクを消さない', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    await screen.advance(2500);
    await screen.item(1).fire('transitionend', { propertyName: 'height' });
    assert.equal(screen.item(1), null);
    await screen.advance(200);
    assert.ok(screen.item(2));
    assert.equal(screen.element('#task-count').textContent, '2件');
});

test('続けて完了したカードのトランジションを、先のカードの消去で中断しない', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    await screen.advance(100);
    await screen.check(2, true);
    await screen.advance(2600);
    assert.equal(screen.item(1), null);
    assert.equal(screen.item(2).classList.contains('task-removing'), true);
    assert.equal(screen.element('#task-count').textContent, '2件');
    await screen.advance(100);
    assert.equal(screen.item(2), null);
    assert.equal(screen.element('#task-count').textContent, '1件');
});

test('確認ポップアップの表示中に別のタスクが完了しても、確認対象を維持する', async () => {
    const screen = await createScreen();
    await screen.check(1, true);
    await screen.saveProgress(3, 100);
    await screen.advance(2700);
    assert.equal(screen.item(1), null);
    assert.equal(screen.element('#completion-dialog').open, true);
    assert.equal(screen.element('#completion-task-title').textContent, 'タスク3');
    await screen.confirm();
    assert.equal(screen.item(3), null);
    assert.ok(screen.item(2));
});

test('ログアウト時に猶予を消去し、以前のアカウントのタイマーや応答を持ち越さない', async () => {
    for (const saving of [false, true]) {
        const screen = await createScreen();
        if (saving) screen.holdCompletion();
        await screen.check(1, true);
        if (saving) await screen.advance(2500);
        screen.run('showAuthView()');
        screen.element('#message').textContent = '';
        await screen.run('getTasks()');
        if (saving) screen.releaseCompletion();
        await screen.advance(3000);
        assert.ok(screen.item(1));
        assert.equal(screen.item(1).classList.contains('task-completion-pending'), false);
        assert.equal(screen.element('#message').textContent, '');
        assert.equal(screen.requests.filter(request => request.url === '/api/complete_task.php').length, saving ? 1 : 0);
    }
});
