// 設定名はCSSの --theme-* と、配色保存APIの項目名に対応する。
const colorGroups = [
    ['画面・カード', [
        ['page', '外側の背景'],
        ['surface', 'ページの背景'],
        ['card', 'カードの背景'],
        ['input', '入力欄の背景'],
    ]],
    ['ボタン', [
        ['primary', 'メインボタンの背景'],
        ['primary_text', 'メインボタンの文字'],
        ['secondary', 'サブボタンの背景'],
        ['secondary_text', 'サブボタンの文字'],
    ]],
    ['ヘッダー・フッター', [
        ['header', 'ヘッダーの背景'],
        ['navigation', 'フッターの背景'],
        ['navigation_active', 'フッターの選択背景'],
    ]],
    ['文字・罫線・進捗', [
        ['text', '本文・見出し・アイコン'],
        ['border', '罫線・入力枠'],
        ['progress', '進捗バー・チェックボックス'],
    ]],
];
const colorsForm = document.querySelector('#colors-form');
const colorFields = document.querySelector('#color-fields');
const colorsMessage = document.querySelector('#colors-message');
const colorInputs = new Map();
const defaultColors = {};
const defaultAppearance = {
    input_border_width: 1,
    card_border_width: 1,
    button_border_width: 1,
    blur_enabled: false,
    shadow_enabled: true,
    font: 'default',
};
const appearanceControls = {
    input_border_width: document.querySelector('#input-border-width'),
    card_border_width: document.querySelector('#card-border-width'),
    button_border_width: document.querySelector('#button-border-width'),
    blur_enabled: document.querySelector('#appearance-blur'),
    shadow_enabled: document.querySelector('#appearance-shadow'),
    font: document.querySelector('#appearance-font'),
};
// 任意のCSSやフォントURLは受け取らず、選択肢に対応したフォントだけを適用する。
const appearanceFonts = {
    default: "'DM Sans', 'Noto Sans JP', sans-serif",
    sans: "'Noto Sans JP', sans-serif",
    serif: "'Noto Serif JP', 'Yu Mincho', serif",
    rounded: "'Zen Maru Gothic', 'Noto Sans JP', sans-serif",
    system: 'system-ui, sans-serif',
};
let savedColors = {};
let draftColors = {};
let savedAppearance = {};
let draftAppearance = {};
let colorsRequestId = 0;
let settingsReady = false;
let settingsSaveQueue = null;
let settingsStorageKey = null;
let undoSettings = null;
const paletteExport = document.querySelector('#palette-export');
const paletteImport = document.querySelector('#palette-import');
const paletteMessage = document.querySelector('#palette-message');

function isHexColor(value) {
    return typeof value === 'string' && /^#[0-9A-Fa-f]{6}$/.test(value);
}

function applyColors(colors) {
    const root = document.documentElement;
    for (const key of Object.keys(defaultColors)) {
        root.style.removeProperty(`--theme-${key}`);
        if (isHexColor(colors[key])) {
            root.style.setProperty(`--theme-${key}`, colors[key]);
        }
    }
    document.querySelector('meta[name="theme-color"]').content = colors.header ?? defaultColors.header;
}

function applyAppearance(settings) {
    const appearance = { ...defaultAppearance, ...settings };
    const root = document.documentElement;
    for (const key of ['input_border_width', 'card_border_width', 'button_border_width']) {
        const value = appearance[key];
        if (Number.isInteger(value) && value >= 0 && value <= 6) {
            root.style.setProperty(`--appearance-${key.replaceAll('_', '-')}`, `${value}px`);
        }
    }
    root.dataset.blurEnabled = String(appearance.blur_enabled === true);
    root.dataset.shadowEnabled = String(appearance.shadow_enabled !== false);
    root.style.setProperty('--appearance-font-family', appearanceFonts[appearance.font] ?? appearanceFonts.default);
}

function refreshAppearanceFields() {
    const appearance = { ...defaultAppearance, ...draftAppearance };
    for (const [key, control] of Object.entries(appearanceControls)) {
        if (control.type === 'checkbox') {
            control.checked = appearance[key];
        } else {
            control.value = appearance[key];
            control.setCustomValidity('');
        }
    }
}

function refreshSettingsFields() {
    refreshColorFields();
    refreshAppearanceFields();
    refreshPaletteExport();
}

function previewAppearance(key) {
    if (!settingsReady) return;
    const control = appearanceControls[key];
    let value;
    if (control.type === 'checkbox') {
        value = control.checked;
    } else if (control.type === 'number') {
        value = Number(control.value);
        if (control.value === '' || !Number.isInteger(value) || value < 0 || value > 6) {
            control.setCustomValidity('枠の太さは0〜6pxの整数で指定してください。');
            return;
        }
        control.setCustomValidity('');
    } else {
        value = control.value;
    }
    rememberSettingsBeforeEditing();
    if (value === defaultAppearance[key]) {
        delete draftAppearance[key];
    } else {
        draftAppearance[key] = value;
    }
    applyAppearance(draftAppearance);
    scheduleSettingsSave();
}

function refreshColorFields() {
    for (const [key, inputs] of colorInputs) {
        const value = draftColors[key] ?? defaultColors[key];
        inputs.picker.value = value;
        inputs.code.value = value;
        inputs.code.setCustomValidity('');
    }
}

function previewColor(key, value) {
    if (!settingsReady) return;
    const { picker, code } = colorInputs.get(key);
    if (!isHexColor(value)) {
        code.setCustomValidity('#から始まる6桁のカラーコードを入力してください。');
        return;
    }
    code.setCustomValidity('');
    const color = value.toUpperCase();
    picker.value = color;
    code.value = color;
    rememberSettingsBeforeEditing();
    if (color === defaultColors[key]) {
        delete draftColors[key];
    } else {
        draftColors[key] = color;
    }
    applyColors(draftColors);
    refreshPaletteExport();
    scheduleSettingsSave();
}

function setColorControlsDisabled(disabled) {
    for (const control of colorsForm.querySelectorAll('input, select, textarea, button')) {
        control.disabled = disabled;
    }
}

function rememberSettingsBeforeEditing() {
    if (!undoSettings) {
        undoSettings = { colors: { ...savedColors }, appearance: { ...savedAppearance } };
    }
}

function cacheSettings(settings, pending) {
    if (!settingsStorageKey) return;
    try {
        // 保存待ちの編集もユーザーID別に記録し、すぐにリロードしても失わない。
        localStorage.setItem(settingsStorageKey, JSON.stringify({ ...settings, pending }));
    } catch {
        // ローカル保存が使えなくても、アカウントへのDB保存は続ける。
    }
}

function readCachedSettings() {
    try {
        const cache = JSON.parse(localStorage.getItem(settingsStorageKey));
        if (!cache || typeof cache.pending !== 'boolean') return null;
        const colors = paletteFormat.normalizeColors(cache.colors, defaultColors);
        const appearance = cache.appearance;
        if (!appearance || typeof appearance !== 'object' || Array.isArray(appearance)) return null;
        for (const [key, value] of Object.entries(appearance)) {
            if (!Object.hasOwn(defaultAppearance, key)) return null;
            if (key.endsWith('_border_width') && (!Number.isInteger(value) || value < 0 || value > 6)) return null;
            if (key.endsWith('_enabled') && typeof value !== 'boolean') return null;
            if (key === 'font' && !Object.hasOwn(appearanceFonts, value)) return null;
        }
        return { colors, appearance, pending: cache.pending };
    } catch {
        return null;
    }
}

function resetUserColors() {
    colorsRequestId++;
    settingsSaveQueue?.dispose();
    settingsSaveQueue = null;
    settingsStorageKey = null;
    settingsReady = false;
    savedColors = {};
    draftColors = {};
    savedAppearance = {};
    draftAppearance = {};
    undoSettings = null;
    applyColors({});
    applyAppearance({});
    refreshSettingsFields();
    paletteImport.value = '';
    paletteMessage.textContent = '';
    colorsMessage.textContent = '';
    setColorControlsDisabled(true);
}

async function loadUserColors(userId) {
    resetUserColors();
    settingsStorageKey = `custom-todo-settings-v1:${userId}`;
    const cached = readCachedSettings();
    if (cached) {
        applyColors(cached.colors);
        applyAppearance(cached.appearance);
    }
    const requestId = colorsRequestId;
    const result = await requestJson('/api/colors.php', { cache: 'no-store' });
    if (requestId !== colorsRequestId) return;
    savedColors = result.data.colors;
    savedAppearance = result.data.appearance ?? {};
    draftColors = { ...(cached?.pending ? cached.colors : savedColors) };
    draftAppearance = { ...(cached?.pending ? cached.appearance : savedAppearance) };
    applyColors(draftColors);
    applyAppearance(draftAppearance);
    refreshSettingsFields();
    settingsReady = true;
    setColorControlsDisabled(false);
    settingsSaveQueue = new SettingsSaveQueue({
        send: async (settings) => (await requestJson('/api/colors.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(settings),
            keepalive: true,
        })).data,
        onSaving: (isLatest) => {
            if (isLatest) colorsMessage.textContent = 'アカウントに保存しています。';
        },
        onSaved: (data, isLatest, successMessage) => {
            savedColors = data.colors;
            savedAppearance = data.appearance ?? {};
            if (isLatest) {
                cacheSettings({ colors: savedColors, appearance: savedAppearance }, false);
                colorsMessage.textContent = successMessage;
            }
        },
        onError: (error, isLatest) => {
            if (isLatest) colorsMessage.textContent = `${error.message}「今すぐ保存」で再試行できます。`;
        },
    });
    if (cached?.pending) {
        scheduleSettingsSave(true);
    } else {
        cacheSettings({ colors: savedColors, appearance: savedAppearance }, false);
        colorsMessage.textContent = '保存済みの設定を読み込みました。';
    }
}

function scheduleSettingsSave(immediate = false, successMessage = 'アカウントに自動保存しました。') {
    if (!settingsReady) return;
    if (!colorsForm.checkValidity()) {
        colorsMessage.textContent = '未保存の入力があります。カラーコードや枠の太さを確認してください。';
        return;
    }
    const settings = { colors: { ...draftColors }, appearance: { ...draftAppearance } };
    cacheSettings(settings, true);
    colorsMessage.textContent = '自動保存を待っています。';
    return settingsSaveQueue.schedule(settings, successMessage, immediate);
}

function showSettingsCategory(category) {
    const descriptions = {
        colors: ['配色', '背景・ボタン・文字などの色を変更できます。変更はアカウントに自動保存されます。'],
        effects: ['枠・効果', '枠の太さ、背景ブラー、影を設定できます。変更は自動保存されます。'],
        fonts: ['フォント', '書体を選ぶとサイト全体に反映され、自動保存されます。'],
        sharing: ['カラーの共有', '配色を文字列で共有し、インポートできます。'],
        account: ['アカウント', 'ログイン中のアカウントを確認できます。'],
    };
    const [title, description] = descriptions[category];
    document.querySelector('#settings-category-title').textContent = title;
    document.querySelector('#settings-category-description').textContent = description;
    document.querySelector('#page-title').textContent = title;
    for (const panel of document.querySelectorAll('[data-settings-category]')) {
        panel.classList.toggle('hidden', panel.dataset.settingsCategory !== category);
    }
    document.querySelector('#settings-preview').classList.toggle('hidden', ['sharing', 'account'].includes(category));
    colorsForm.classList.toggle('hidden', category === 'account');
}

function refreshPaletteExport() {
    paletteExport.value = paletteFormat.export(draftColors, defaultColors);
}

function buildColorFields() {
    const defaultStyle = getComputedStyle(document.documentElement);
    for (const [heading, fields] of colorGroups) {
        const group = document.createElement('fieldset');
        group.className = 'rounded-2xl border border-line bg-card p-5 shadow-sm';
        const legend = document.createElement('legend');
        legend.className = 'px-2 text-sm font-bold';
        legend.textContent = heading;
        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-1 gap-5 sm:grid-cols-2';
        for (const [key, labelText] of fields) {
            let defaultColor = defaultStyle.getPropertyValue(`--theme-${key}`).trim().toUpperCase();
            // CSSのビルドで #FFFFFF が #fff に短縮されても、入力欄は6桁に揃える。
            if (/^#[0-9A-F]{3}$/.test(defaultColor)) {
                defaultColor = '#' + [...defaultColor.slice(1)].map((digit) => digit + digit).join('');
            }
            defaultColors[key] = defaultColor;
            const field = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'mb-2 block text-sm font-semibold';
            label.htmlFor = `color-${key}`;
            label.textContent = labelText;
            const controls = document.createElement('div');
            controls.className = 'flex items-center gap-2';
            const picker = document.createElement('input');
            picker.type = 'color';
            picker.id = `color-${key}`;
            picker.className = 'h-11 w-12 shrink-0 cursor-pointer rounded-lg border border-line bg-field p-1';
            const code = document.createElement('input');
            code.type = 'text';
            code.id = `color-${key}-code`;
            code.setAttribute('aria-label', `${labelText}のカラーコード`);
            code.className = 'min-w-0 w-full rounded-lg border border-line bg-field px-3 py-2 font-mono text-sm focus:border-main focus:outline-none focus:ring-2 focus:ring-accent';
            code.maxLength = 7;
            code.pattern = '#[0-9A-Fa-f]{6}';
            code.required = true;
            code.spellcheck = false;
            colorInputs.set(key, { picker, code });
            picker.addEventListener('input', () => previewColor(key, picker.value));
            code.addEventListener('input', () => previewColor(key, code.value));
            controls.append(picker, code);
            field.append(label, controls);
            grid.append(field);
        }
        group.append(legend, grid);
        colorFields.append(group);
    }
    refreshSettingsFields();
    setColorControlsDisabled(true);
}

colorsForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!colorsForm.checkValidity()) {
        const invalid = colorsForm.querySelector(':invalid');
        const category = invalid.closest('[data-settings-category]')?.dataset.settingsCategory;
        if (category) {
            window.location.hash = `settings/${category}`;
            showSettingsCategory(category);
        }
        invalid.reportValidity();
        return;
    }
    scheduleSettingsSave(true, '表示設定を保存しました。');
});

document.querySelector('#cancel-colors').addEventListener('click', () => {
    if (!undoSettings) {
        colorsMessage.textContent = '取り消す変更はありません。';
        return;
    }
    draftColors = { ...undoSettings.colors };
    draftAppearance = { ...undoSettings.appearance };
    undoSettings = null;
    applyColors(draftColors);
    applyAppearance(draftAppearance);
    refreshSettingsFields();
    scheduleSettingsSave(true, '変更前の設定に戻して保存しました。');
});

document.querySelector('#reset-colors').addEventListener('click', () => {
    rememberSettingsBeforeEditing();
    draftColors = {};
    draftAppearance = {};
    applyColors({});
    applyAppearance({});
    refreshSettingsFields();
    scheduleSettingsSave(true, '初期設定に戻して保存しました。');
});

document.querySelector('#copy-palette').addEventListener('click', async () => {
    if (![...colorInputs.values()].every(({ code }) => code.checkValidity())) {
        paletteMessage.textContent = '入力したカラーコードを確認してください。';
        return;
    }
    refreshPaletteExport();
    try {
        await navigator.clipboard.writeText(paletteExport.value);
        paletteMessage.textContent = '配色の共有文字列をコピーしました。';
    } catch {
        paletteExport.focus();
        paletteExport.select();
        if (document.execCommand('copy')) {
            paletteMessage.textContent = '配色の共有文字列をコピーしました。';
        } else {
            paletteMessage.textContent = '共有文字列を選択しました。コピーして共有してください。';
        }
    }
});

document.querySelector('#import-palette').addEventListener('click', () => {
    try {
        const imported = paletteFormat.import(paletteImport.value, defaultColors);
        rememberSettingsBeforeEditing();
        draftColors = imported;
        applyColors(draftColors);
        refreshColorFields();
        refreshPaletteExport();
        paletteMessage.textContent = '配色を読み込みました。';
        scheduleSettingsSave(true, 'インポートした配色をアカウントに保存しました。');
    } catch (error) {
        paletteMessage.textContent = error.message;
    }
});

for (const [key, control] of Object.entries(appearanceControls)) {
    control.addEventListener(control.type === 'number' ? 'input' : 'change', () => previewAppearance(key));
}
window.addEventListener('pagehide', () => settingsSaveQueue?.flush());
buildColorFields();
