const { test } = require('node:test');
const assert = require('node:assert/strict');
const schedule = require('../src/public/assets/task-schedule.js');

const now = Date.parse('2026-10-20T12:00:00+09:00');
const period = { task_type: 'period', starts_at: '2026-10-20T13:00:00+09:00', scheduled_at: '2026-10-21T12:00:00+09:00' };

test('日時なしではカウントダウンを表示しない', () => {
    assert.deepEqual(schedule.timing({ task_type: 'unscheduled' }, now), { status: 'unscheduled', text: '' });
});

test('期限のみでは開始日時なしで締切までの残りを表示する', () => {
    assert.deepEqual(schedule.timing({ task_type: 'deadline', scheduled_at: period.scheduled_at }, now), { status: 'deadline', text: '残り 1日' });
});

test('期間の開始前は開始と締切の両方までの時間がわかる', () => {
    assert.deepEqual(schedule.timing(period, now), { status: 'upcoming', text: '開始まで 1時間 ／ 締切まで 1日' });
});

test('開始の瞬間から進行中へ自動で切り替わる', () => {
    assert.deepEqual(schedule.timing(period, now + 3600000), { status: 'active', text: '進行中 ／ 残り 23時間' });
});

test('1分未満の残りは秒単位で減る', () => {
    const deadline = { task_type: 'deadline', scheduled_at: '2026-10-20T12:00:30+09:00' };
    assert.equal(schedule.timing(deadline, now).text, '残り 30秒');
    assert.equal(schedule.timing(deadline, now + 1000).text, '残り 29秒');
});

test('締切の瞬間と期限超過後を表示する', () => {
    assert.deepEqual(schedule.timing(period, now + 86400000), { status: 'overdue', text: '締切になりました' });
    assert.deepEqual(schedule.timing(period, now + 86400001), { status: 'overdue', text: '期限超過 1秒' });
    assert.deepEqual(schedule.timing(period, now + 90000000), { status: 'overdue', text: '期限超過 1時間' });
});

test('長い残り時間は日・時間・分で表示し、0分とは表示しない', () => {
    assert.equal(schedule.duration((2 * 86400 + 3 * 3600 + 5 * 60) * 1000), '2日 3時間 5分');
    assert.equal(schedule.duration(60001), '2分');
    assert.equal(schedule.duration(1), '1秒');
});

test('PostgreSQLの日時形式とタイムゾーンの違いで残り時間がずれない', () => {
    for (const value of ['2026-10-21 03:00:00+00', '2026-10-21T12:00:00.000000+09:00']) {
        assert.equal(schedule.timing({ task_type: 'deadline', scheduled_at: value }, now).text, '残り 1日');
    }
});

test('古いタスクの予定日時からも種類を判定できる', () => {
    assert.equal(schedule.typeOf({ scheduled_at: period.scheduled_at }), 'deadline');
    assert.equal(schedule.typeOf({ scheduled_at: null }), 'unscheduled');
});

test('壊れた日時でNaNなどの残り時間を表示しない', () => {
    assert.equal(schedule.timing({ task_type: 'deadline', scheduled_at: 'invalid' }, now).status, 'invalid');
    assert.equal(schedule.timing({ ...period, starts_at: 'invalid' }, now).status, 'invalid');
});

test('TODOは締切日の昇順にまとめ、日時なしは最後に表示する', () => {
    const tasks = [
        { id: 1, task_type: 'deadline', scheduled_at: new Date(2026, 9, 22, 10).toISOString() },
        { id: 2, task_type: 'unscheduled', scheduled_at: null },
        { id: 3, task_type: 'deadline', scheduled_at: new Date(2026, 9, 20, 10).toISOString() },
    ];
    const groups = schedule.groupByDate(tasks);
    assert.deepEqual(groups.map(group => group.date), ['2026-10-20', '2026-10-22', null]);
    assert.deepEqual(groups.flatMap(group => group.tasks.map(task => task.id)), [3, 1, 2]);
    assert.deepEqual(tasks.map(task => task.id), [1, 2, 3]);
});

test('期間タスクは開始日ではなく終了日で分類し、同じ日の保存済み順序を保つ', () => {
    const tasks = [
        { id: 2, task_type: 'period', starts_at: new Date(2026, 9, 20, 9).toISOString(), scheduled_at: new Date(2026, 9, 22, 17).toISOString() },
        { id: 1, task_type: 'deadline', scheduled_at: new Date(2026, 9, 22, 9).toISOString() },
    ];
    const groups = schedule.groupByDate(tasks);
    assert.equal(groups.length, 1);
    assert.equal(groups[0].date, '2026-10-22');
    assert.deepEqual(groups[0].tasks.map(task => task.id), [2, 1]);
});

test('日付の分類はUTCの日付ではなくブラウザーの現地日付を使う', () => {
    const localMidnight = new Date(2026, 9, 20, 0, 5);
    const localNight = new Date(2026, 9, 20, 23, 55);
    assert.equal(schedule.dayKey(localMidnight.toISOString()), '2026-10-20');
    assert.equal(schedule.dayKey(localNight.toISOString()), '2026-10-20');
});

test('今日・明日のラベルは日付が変わると更新される', () => {
    const today = new Date(2026, 9, 20, 12).getTime();
    const tomorrow = new Date(2026, 9, 21, 12).getTime();
    assert.match(schedule.dateLabel('2026-10-20', today), /^今日 · /);
    assert.match(schedule.dateLabel('2026-10-21', today), /^明日 · /);
    assert.match(schedule.dateLabel('2026-10-21', tomorrow), /^今日 · /);
    assert.doesNotMatch(schedule.dateLabel('2026-10-20', tomorrow), /今日|明日/);
    assert.equal(schedule.dateLabel(null, today), '日時なし');
});

test('0件のTODOは空のグループ一覧になる', () => {
    assert.deepEqual(schedule.groupByDate([]), []);
});
