// 配色の共有には色だけを含める。アカウントや表示効果の情報は含めない。
const paletteFormat = {
    normalizeColors(colors, defaults, requireAll = false) {
        if (!colors || typeof colors !== 'object' || Array.isArray(colors)) {
            throw new Error('配色データの形式が正しくありません。');
        }
        const normalized = {};
        for (const [key, value] of Object.entries(colors)) {
            if (!Object.hasOwn(defaults, key) || typeof value !== 'string' || !/^#[0-9a-fA-F]{6}$/.test(value)) {
                throw new Error('配色の項目とカラーコードを確認してください。');
            }
            normalized[key] = value.toUpperCase();
        }
        if (requireAll && Object.keys(normalized).length !== Object.keys(defaults).length) {
            throw new Error('配色の項目が不足しています。共有文字列をすべて貼り付けてください。');
        }
        return normalized;
    },

    export(colors, defaults) {
        const completeColors = { ...defaults, ...this.normalizeColors(colors, defaults) };
        return JSON.stringify({ format: 'custom-todo-palette', version: 1, colors: completeColors }, null, 2);
    },

    import(text, defaults) {
        if (typeof text !== 'string' || text.length > 16384) {
            throw new Error('共有文字列が長すぎます。エクスポートした文字列を貼り付けてください。');
        }
        let data;
        try {
            data = JSON.parse(text.trim());
        } catch {
            throw new Error('共有文字列を読み取れませんでした。コピーした文字列をすべて貼り付けてください。');
        }
        if (!data || data.format !== 'custom-todo-palette' || data.version !== 1) {
            throw new Error('Custom ToDoの共有文字列を貼り付けてください。');
        }
        return this.normalizeColors(data.colors, defaults, true);
    },
};

if (typeof module !== 'undefined') {
    module.exports = paletteFormat;
}
