const { test } = require('node:test');
const assert = require('node:assert/strict');
const recurrence = require('../src/public/assets/task-recurrence.js');
const { createScreen } = require('./helpers/task-screen.cjs');

test('作成・編集の繰り返しボタンで詳細設定を開閉し、無効時は入力を送らない', async () => {
    const screen = await createScreen();
    for (const name of ['taskForm', 'editTaskForm']) {
        const form = screen.run(name);
        const toggle = form.querySelector('[data-repeat-toggle]');
        assert.equal(form.querySelector('[data-repeat-settings]').hidden, true);
        await toggle.fire('click');
        assert.equal(toggle['aria-expanded'], 'true');
        assert.equal(form.querySelector('[data-repeat-settings]').hidden, false);
        assert.equal(form.elements.namedItem('repeat_time').disabled, false);
        await toggle.fire('click');
        assert.equal(form.querySelector('[data-repeat-settings]').hidden, true);
        assert.deepEqual(recurrence.payload(form), { repeat_type: 'none', repeat_day: null, repeat_time: null, repeat_timezone: null });
    }
});

test('毎月はカレンダーの日付から「日」を取り出し、毎週は曜日を送る', async () => {
    const screen = await createScreen();
    const form = screen.run('taskForm');
    await form.querySelector('[data-repeat-toggle]').fire('click');
    form.dataset.repeatTimezone = 'Asia/Tokyo';
    form.elements.namedItem('repeat_type').value = 'monthly';
    form.elements.namedItem('repeat_month_date').value = '2026-12-31';
    assert.equal(recurrence.updateForm(form), '');
    assert.equal(form.elements.namedItem('repeat_month_date').required, true);
    assert.equal(form.elements.namedItem('repeat_weekday').disabled, true);
    assert.deepEqual(recurrence.payload(form), { repeat_type: 'monthly', repeat_day: 31, repeat_time: '09:00', repeat_timezone: 'Asia/Tokyo' });
    form.elements.namedItem('repeat_type').value = 'weekly';
    form.elements.namedItem('repeat_weekday').value = '3';
    assert.equal(recurrence.updateForm(form), '');
    assert.equal(form.elements.namedItem('repeat_month_date').disabled, true);
    assert.equal(recurrence.payload(form).repeat_day, 3);
});

test('毎日は時刻を指定し、日付・曜日は不要になる。不正な時刻は修正できる', async () => {
    const screen = await createScreen();
    const form = screen.run('taskForm');
    await form.querySelector('[data-repeat-toggle]').fire('click');
    form.elements.namedItem('repeat_time').value = '24:00';
    assert.match(recurrence.updateForm(form), /時刻/);
    form.elements.namedItem('repeat_time').value = '08:30';
    assert.equal(recurrence.updateForm(form), '');
    assert.equal(form.elements.namedItem('repeat_month_date').disabled, true);
    assert.equal(form.elements.namedItem('repeat_weekday').disabled, true);
    assert.equal(recurrence.payload(form).repeat_day, null);
    assert.equal(recurrence.payload(form).repeat_time, '08:30');
});

test('編集では保存した曜日・時刻・タイムゾーンを復元し、月末31日も正しく表示する', async () => {
    const screen = await createScreen();
    const form = screen.run('editTaskForm');
    recurrence.fillForm(form, { repeat_type: 'weekly', repeat_day: 4, repeat_time: '18:30:00', repeat_timezone: 'Asia/Tokyo' });
    assert.equal(form.querySelector('[data-repeat-settings]').hidden, false);
    assert.equal(form.elements.namedItem('repeat_weekday').value, '4');
    assert.equal(form.elements.namedItem('repeat_time').value, '18:30');
    assert.equal(recurrence.payload(form).repeat_timezone, 'Asia/Tokyo');
    recurrence.fillForm(form, { repeat_type: 'monthly', repeat_day: 31, repeat_time: '09:00:00', repeat_timezone: 'Asia/Tokyo' });
    assert.match(form.elements.namedItem('repeat_month_date').value, /-31$/);
    recurrence.fillForm(form);
    assert.equal(form.dataset.repeating, 'false');
    assert.equal(form.querySelector('[data-repeat-settings]').hidden, true);
});

test('一覧・アーカイブに毎月・毎週・毎日の設定を表示する', () => {
    assert.equal(recurrence.label({ repeat_type: 'none' }), '');
    assert.equal(recurrence.label({ repeat_type: 'daily', repeat_time: '08:30:00' }), '毎日 08:30');
    assert.equal(recurrence.label({ repeat_type: 'weekly', repeat_day: 3, repeat_time: '09:00:00' }), '毎週水曜日 09:00');
    assert.equal(recurrence.label({ repeat_type: 'monthly', repeat_day: 31, repeat_time: '18:00:00' }), '毎月31日 18:00');
});

const nextTask = {
    id: 4, title: 'タスク1', progress: 0, background_color: '#FECACA',
    task_type: 'deadline', starts_at: null, scheduled_at: '2099-11-11T09:00:00+09:00',
    repeat_type: 'daily', repeat_day: null, repeat_time: '09:00:00', repeat_timezone: 'Asia/Tokyo',
};

test('チェック解除では次回分を作らず、完了確定後には次回分を一覧へ表示する', async () => {
    const screen = await createScreen();
    screen.nextRecurringTask({ ...nextTask });
    await screen.check(1, true);
    await screen.check(1, false);
    await screen.advance(3000);
    assert.equal(screen.item(4), null);
    await screen.check(1, true);
    await screen.advance(2700);
    assert.equal(screen.item(1), null);
    assert.ok(screen.item(4));
    assert.equal(screen.item(4).querySelector('details').style['--task-line-color'], '#FECACA');
    assert.equal(screen.item(4).querySelector('.task-repeat-label').textContent, '毎日 09:00');
    assert.equal(screen.element('#task-count').textContent, '3件');
    assert.match(screen.element('#message').textContent, /次回分/);
});

test('進捗100%の確認ポップアップから完了した場合も、次回分を瞬時に表示する', async () => {
    const screen = await createScreen();
    screen.nextRecurringTask({ ...nextTask });
    await screen.saveProgress(1, 100);
    await screen.confirm();
    assert.equal(screen.item(1), null);
    assert.ok(screen.item(4));
    assert.equal(screen.element('#completion-dialog').open, false);
});
