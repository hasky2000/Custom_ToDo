const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createScreen } = require('./helpers/task-screen.cjs');

async function createDragScreen() {
    const screen = await createScreen();
    for (const id of [1, 2, 3]) {
        const item = screen.item(id);
        item.getBoundingClientRect = () => {
            const top = 200 + item.parentElement.children.indexOf(item) * 140;
            return { left: 100, right: 500, top, bottom: top + 120, width: 400, height: 120 };
        };
        const [checkbox, disclosure] = item.querySelectorAll('[data-task-drag-exclude]');
        checkbox.getBoundingClientRect = () => ({ left: 116, right: 140, top: 248, bottom: 272 });
        disclosure.getBoundingClientRect = () => ({ left: 448, right: 484, top: 242, bottom: 278 });
    }
    screen.pointerDown = async (id, x = 280, y = 260, extra = {}) => {
        const summary = screen.item(id).querySelector('summary');
        await summary.fire('pointerdown', {
            button: 0, isPrimary: true, pointerId: 1, clientX: x, clientY: y, ...extra,
        });
        return summary;
    };
    return screen;
}

test('見出し中央で押しても5px未満の移動では並べ替えず、通常クリックを妨げない', async () => {
    const screen = await createDragScreen();
    let prevented = false;
    const summary = await screen.pointerDown(1, 280, 260, { preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(screen.run('dragState !== null'), true);
    assert.equal(summary.hasPointerCapture(1), false);
    await screen.pointer('pointermove', { clientY: 264 });
    assert.equal(screen.run('dragState.preview'), null);
    await screen.pointer('pointerup');
    await summary.fire('click', { detail: 1, preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(screen.requests.length, 1);
});

test('見出し中央からドラッグして並び順を保存し、ドラッグ後の詳細開閉を抑止する', async () => {
    const screen = await createDragScreen();
    const summary = await screen.pointerDown(1);
    await screen.pointer('pointermove', { clientY: 560 });
    assert.equal(summary.hasPointerCapture(1), true);
    assert.equal(screen.run('taskOrder().join(",")'), '2,3,1');
    await screen.pointer('pointerup');
    assert.equal(summary.hasPointerCapture(1), false);
    const reorder = screen.requests.find(request => request.url === '/api/reorder_task.php');
    assert.deepEqual(JSON.parse(reorder.body), { task_ids: [2, 3, 1] });
    let prevented = false;
    await summary.fire('click', { detail: 1, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    await screen.run('getTasks()');
    assert.equal(screen.run('taskOrder().join(",")'), '2,3,1');
});

test('チェックボックス・開閉ボタンと外周12pxからはドラッグを開始しない', async () => {
    const screen = await createDragScreen();
    for (const [x, y] of [[128, 260], [152, 260], [104, 260], [128, 236], [466, 260], [436, 260], [496, 260], [466, 230]]) {
        const summary = await screen.pointerDown(1, x, y);
        assert.equal(screen.run('dragState'), null, `${x},${y}はドラッグ対象外です。`);
        assert.equal(summary.hasPointerCapture(1), false);
        let prevented = false;
        await summary.fire('click', { detail: 1, preventDefault() { prevented = true; } });
        assert.equal(prevented, false);
    }
    assert.equal(screen.requests.length, 1);
});

test('除外範囲を外れた余白からはドラッグを開始できる', async () => {
    const screen = await createDragScreen();
    for (const x of [154, 434]) {
        await screen.pointerDown(1, x);
        assert.equal(screen.run('dragState !== null'), true);
        await screen.pointer('pointercancel');
        assert.equal(screen.run('dragState'), null);
    }
});

test('Escapeで並び順を戻し、次の通常クリックでは詳細開閉を妨げない', async () => {
    const screen = await createDragScreen();
    await screen.pointerDown(1);
    await screen.pointer('pointermove', { clientY: 560 });
    await screen.pointer('keydown', { key: 'Escape' });
    assert.equal(screen.run('taskOrder().join(",")'), '1,2,3');
    const summary = await screen.pointerDown(1);
    await screen.pointer('pointerup');
    let prevented = false;
    await summary.fire('click', { detail: 1, preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(screen.requests.length, 1);
});

test('右クリック・副ポインター・完了猶予中・保存中・1件だけの場合は開始しない', async () => {
    const screen = await createDragScreen();
    for (const extra of [{ button: 2 }, { isPrimary: false }]) {
        await screen.pointerDown(1, 280, 260, extra);
        assert.equal(screen.run('dragState'), null);
    }
    await screen.check(1, true);
    await screen.pointerDown(2);
    assert.equal(screen.run('dragState'), null);
    assert.equal(screen.item(2).querySelector('summary').dataset.dragEnabled, 'false');
    await screen.check(1, false);
    assert.equal(screen.item(2).querySelector('summary').dataset.dragEnabled, 'true');
    screen.run('isReordering = true; updateTaskDragAreas()');
    await screen.pointerDown(2);
    assert.equal(screen.run('dragState'), null);
    screen.run('isReordering = false; tasks = tasks.slice(0, 1); renderTasks()');
    await screen.pointerDown(1);
    assert.equal(screen.run('dragState'), null);
});

test('点状ハンドルを使わず、見出しのキーボード操作で並び順を保存できる', async () => {
    const screen = await createDragScreen();
    assert.equal(screen.item(2).querySelector('[data-drag-handle]'), null);
    await screen.item(2).querySelector('summary').fire('keydown', { key: 'ArrowUp' });
    await screen.advance(0);
    const reorder = screen.requests.find(request => request.url === '/api/reorder_task.php');
    assert.deepEqual(JSON.parse(reorder.body), { task_ids: [2, 1, 3] });
    assert.equal(screen.run('taskOrder().join(",")'), '2,1,3');
});

test('チェックボックスにフォーカスしている間は、上下キーでタスクを移動しない', async () => {
    const screen = await createDragScreen();
    await screen.item(2).querySelector('summary').fire('keydown', {
        key: 'ArrowUp', target: screen.item(2).querySelector('input[type="checkbox"]'),
    });
    assert.equal(screen.run('taskOrder().join(",")'), '1,2,3');
    assert.equal(screen.requests.length, 1);
});
