<?php

declare(strict_types=1);

/** 作成・編集で同じ繰り返し設定欄を使い、IDだけを分ける。 */
function renderTaskRecurrenceFields(string $prefix): void
{
    $prefix = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="task-repeat-control">
        <button id="<?= $prefix ?>repeat-toggle" type="button" data-repeat-toggle aria-expanded="false" aria-pressed="false"
            aria-controls="<?= $prefix ?>repeat-settings" class="task-repeat-toggle">繰り返し</button>
        <div id="<?= $prefix ?>repeat-settings" data-repeat-settings class="task-repeat-settings space-y-4" hidden>
            <p class="text-sm leading-6">完了すると、この設定で次回分のタスクを自動作成します。もう一度「繰り返し」を押すと解除します。</p>
            <div>
                <label for="<?= $prefix ?>repeat-type" class="block font-medium">繰り返しの単位</label>
                <select id="<?= $prefix ?>repeat-type" name="repeat_type" disabled class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2">
                    <option value="monthly">毎月</option>
                    <option value="weekly">毎週</option>
                    <option value="daily" selected>毎日</option>
                </select>
            </div>
            <div data-repeat-month hidden>
                <label for="<?= $prefix ?>repeat-month-date" class="block font-medium">繰り返す日付</label>
                <input id="<?= $prefix ?>repeat-month-date" name="repeat_month_date" type="date" disabled
                    aria-describedby="<?= $prefix ?>repeat-month-help" class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2">
                <p id="<?= $prefix ?>repeat-month-help" class="mt-1 text-xs leading-5">選んだ日付の「日」を毎月使います。その日がない月は月末になります。</p>
            </div>
            <div data-repeat-week hidden>
                <label for="<?= $prefix ?>repeat-weekday" class="block font-medium">繰り返す曜日</label>
                <select id="<?= $prefix ?>repeat-weekday" name="repeat_weekday" disabled class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2">
                    <option value="1">月曜日</option><option value="2">火曜日</option><option value="3">水曜日</option>
                    <option value="4">木曜日</option><option value="5">金曜日</option><option value="6">土曜日</option><option value="0">日曜日</option>
                </select>
            </div>
            <div>
                <label for="<?= $prefix ?>repeat-time" class="block font-medium">繰り返す時刻</label>
                <input id="<?= $prefix ?>repeat-time" name="repeat_time" type="time" step="60" value="09:00" disabled
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2">
            </div>
        </div>
    </section>
    <?php
}
