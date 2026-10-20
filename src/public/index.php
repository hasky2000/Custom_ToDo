<?php
// 画面・CSS・JavaScriptの更新が別々にキャッシュされないようにする。
header('Cache-Control: no-cache');
require_once dirname(__DIR__) . '/app/color_templates.php';
$colorTemplates = colorTemplateCatalog();
$defaultColorTemplate = defaultColorTemplate();
$appScriptVersion = filemtime(__DIR__ . '/assets/app.js');
$appStyleVersion = filemtime(__DIR__ . '/assets/app.css');
$colorScriptVersion = filemtime(__DIR__ . '/assets/colors.js');
$paletteScriptVersion = filemtime(__DIR__ . '/assets/palette-format.js');
$queueScriptVersion = filemtime(__DIR__ . '/assets/settings-save-queue.js');
$scheduleScriptVersion = filemtime(__DIR__ . '/assets/task-schedule.js');
?>
<!doctype html>
<html lang="ja" class="initializing">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $defaultColorTemplate['colors']['header'] ?>">
    <link rel="stylesheet" href="/assets/app.css?v=<?= $appStyleVersion ?>">
    <style id="default-color-palette">
        :root {
            <?php foreach ($defaultColorTemplate['colors'] as $key => $color): ?>
            --theme-<?= $key ?>: <?= $color ?>;
            <?php endforeach; ?>
        }
    </style>
    <script id="color-template-data" type="application/json"><?= json_encode($colorTemplates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <title>Custom ToDo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;600;700&family=Noto+Serif+JP:wght@400;500;600;700&family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        main: 'var(--theme-primary)',
                        accent: 'var(--theme-secondary)',
                        page: 'var(--theme-page)',
                        surface: 'var(--theme-surface)',
                        header: 'var(--theme-header)',
                        card: 'var(--theme-card)',
                        field: 'var(--theme-input)',
                        'primary-ink': 'var(--theme-primary_text)',
                        'secondary-ink': 'var(--theme-secondary_text)',
                        ink: 'var(--theme-text)',
                        line: 'var(--theme-border)',
                        progress: 'var(--theme-progress)',
                        navigation: 'var(--theme-navigation)',
                        selected: 'var(--theme-navigation_active)'
                    },
                    boxShadow: {
                        sm: '0 1px 3px var(--color-shadow)',
                        xl: '0 20px 25px -5px var(--color-shadow)',
                        '2xl': '0 25px 50px -12px var(--color-shadow)'
                    },
                    fontFamily: { sans: ['var(--appearance-font-family)'] }
                }
            }
        }
    </script>
    <script src="/assets/palette-format.js?v=<?= $paletteScriptVersion ?>" defer></script>
    <script src="/assets/settings-save-queue.js?v=<?= $queueScriptVersion ?>" defer></script>
    <script src="/assets/colors.js?v=<?= $colorScriptVersion ?>" defer></script>
    <script src="/assets/task-schedule.js?v=<?= $scheduleScriptVersion ?>" defer></script>
    <script src="/assets/app.js?v=<?= $appScriptVersion ?>" defer></script>
</head>
<body class="theme-app min-h-screen bg-page font-sans text-ink">
    <section id="auth-view" class="mx-auto flex min-h-screen max-w-lg items-center bg-surface px-6 py-12 shadow-xl">
        <div class="w-full">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-ink">Custom ToDo</p>
            <h1 class="mt-2 text-3xl font-bold">おかえりなさい</h1>
            <p class="mt-2 text-sm text-ink">メールアドレスとパスワードでログインしてください。</p>

            <div class="mt-8 grid grid-cols-2 rounded-xl bg-accent p-1">
                <button id="show-login" type="button" class="rounded-lg bg-card px-3 py-2 text-sm font-semibold shadow-sm">ログイン</button>
                <button id="show-register" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-ink">新規登録</button>
            </div>

            <form id="login-form" class="mt-6 space-y-4 rounded-2xl bg-card p-6 shadow-sm">
                <div>
                    <label for="login-email" class="block text-sm font-semibold">メールアドレス</label>
                    <input id="login-email" name="email" type="email" autocomplete="email" required
                        class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-3 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
                <div>
                    <label for="login-password" class="block text-sm font-semibold">パスワード</label>
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required
                        class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-3 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
                <button type="submit" class="w-full rounded-lg bg-main px-4 py-3 font-semibold text-primary-ink">ログイン</button>
            </form>

            <form id="register-form" class="mt-6 hidden space-y-4 rounded-2xl bg-card p-6 shadow-sm">
                <div>
                    <label for="register-email" class="block text-sm font-semibold">メールアドレス</label>
                    <input id="register-email" name="email" type="email" autocomplete="email" required
                        class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-3 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
                <div>
                    <label for="register-password" class="block text-sm font-semibold">パスワード</label>
                    <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="8" required
                        class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-3 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                    <p class="mt-1 text-xs text-ink">8文字以上で入力してください。</p>
                </div>
                <div>
                    <label for="register-password-confirmation" class="block text-sm font-semibold">パスワード（確認）</label>
                    <input id="register-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required
                        class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-3 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
                <button type="submit" class="w-full rounded-lg bg-main px-4 py-3 font-semibold text-primary-ink">アカウントを作成</button>
            </form>

            <p id="auth-message" class="mt-4 min-h-6 text-sm font-semibold text-ink" role="status"></p>
        </div>
    </section>

    <div id="app-shell" class="mx-auto hidden min-h-screen max-w-lg bg-surface shadow-xl">
        <div id="app-topbar" class="sticky top-0 z-20">
            <header class="app-header flex items-center justify-between gap-3 border-b border-line bg-header px-4 py-3">
                <a id="header-back" href="#my" class="header-back hidden" aria-label="マイページに戻る">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m14 6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <h1 id="page-title" class="min-w-0 flex-1 text-xl font-bold leading-tight">TODOリスト</h1>
                <p id="current-user-email" class="max-w-[45%] self-start truncate text-right text-xs leading-tight text-ink"></p>
            </header>
        </div>

        <main class="px-5 pb-36 pt-6">
            <p id="message" class="mb-4 min-h-6 text-sm" role="status"></p>

            <section id="todo-page" aria-labelledby="page-title">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <p id="task-order-help" class="min-w-0 flex-1 text-sm text-ink">締切日ごとに表示します。同じ日付の中でドラッグして並べ替えできます。</p>
                    <span id="task-count" class="shrink-0 text-sm text-ink"></span>
                </div>
                <div id="task-list" class="space-y-6"></div>
            </section>

            <section id="archive-page" class="hidden space-y-6" aria-labelledby="completed-list-heading">
                <div class="space-y-4 rounded-2xl border border-line bg-card p-5 shadow-sm">
                    <div>
                        <label for="archive-search" class="block text-sm font-semibold">タスクを検索</label>
                        <input id="archive-search" type="search" placeholder="タイトル・内容で検索"
                            class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                    </div>
                    <div>
                        <label for="archive-date" class="block text-sm font-semibold">完了日で絞り込む</label>
                        <input id="archive-date" type="date" class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
                    </div>
                    <button id="clear-archive-filters" type="button" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-secondary-ink">検索条件をクリア</button>
                </div>
                <div aria-live="polite">
                    <div class="mb-4 flex items-end justify-between">
                        <h2 id="completed-list-heading" class="text-lg font-bold">アーカイブ</h2>
                        <span id="completed-task-count" class="text-sm text-ink"></span>
                    </div>
                    <div id="completed-task-list" class="space-y-6"></div>
                </div>
            </section>

            <section id="my-page" class="hidden space-y-6" aria-label="マイページ">
                <a id="open-archive-page" href="#archive" class="block rounded-2xl border border-line bg-card p-5 font-semibold shadow-sm">アーカイブを見る →</a>
                <a href="#settings" class="block rounded-2xl border border-line bg-card p-5 font-semibold shadow-sm">設定を開く →</a>
                <a href="#debug" class="block rounded-2xl border border-line bg-card p-5 font-semibold shadow-sm">デバッグページを見る →</a>
            </section>

            <section id="settings-page" class="hidden space-y-6" aria-label="設定">
                <div>
                    <h2 class="text-lg font-bold">設定のカテゴリ</h2>
                    <p class="mt-2 text-sm leading-6">変更した設定はアカウントに自動保存されます。</p>
                </div>
                <div class="grid gap-4">
                    <a href="#settings/colors" class="rounded-2xl border border-line bg-card p-5 shadow-sm"><span class="block font-bold">配色 →</span><span class="mt-2 block text-sm">背景・ボタン・文字などの色</span></a>
                    <a href="#settings/effects" class="rounded-2xl border border-line bg-card p-5 shadow-sm"><span class="block font-bold">枠・効果 →</span><span class="mt-2 block text-sm">枠の太さ・背景ブラー・影</span></a>
                    <a href="#settings/fonts" class="rounded-2xl border border-line bg-card p-5 shadow-sm"><span class="block font-bold">フォント →</span><span class="mt-2 block text-sm">文字の書体</span></a>
                    <a href="#settings/sharing" class="rounded-2xl border border-line bg-card p-5 shadow-sm"><span class="block font-bold">カラーの共有 →</span><span class="mt-2 block text-sm">配色文字列のエクスポート・インポート</span></a>
                    <a href="#settings/account" class="rounded-2xl border border-line bg-card p-5 shadow-sm"><span class="block font-bold">アカウント →</span><span class="mt-2 block text-sm">ログイン情報・ログアウト</span></a>
                </div>
            </section>

            <section id="settings-detail-page" class="hidden space-y-6" aria-label="カテゴリ別の設定">
                <div>
                    <h2 id="settings-category-title" class="text-lg font-bold"></h2>
                    <p id="settings-category-description" class="mt-2 text-sm leading-6"></p>
                </div>
                <div id="settings-preview" class="overflow-hidden rounded-2xl border border-line bg-card shadow-sm" aria-label="表示のプレビュー">
                    <div class="bg-header px-5 py-3 font-semibold">プレビュー</div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm">カード・文字・ボタンの見た目を確認できます。</p>
                        <label for="appearance-preview-input" class="block text-sm font-semibold">入力欄のプレビュー</label>
                        <input id="appearance-preview-input" type="text" value="Custom ToDo — TODOリスト" readonly
                            class="w-full rounded-lg border border-line bg-field px-3 py-2">
                        <div class="flex flex-wrap gap-2">
                            <span class="appearance-preview-button rounded-lg bg-main px-4 py-2 text-sm font-semibold text-primary-ink">メインボタン</span>
                            <span class="appearance-preview-button rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-secondary-ink">サブボタン</span>
                        </div>
                    </div>
                </div>
                <form id="colors-form" class="space-y-6" novalidate>
                    <fieldset data-settings-category="effects" class="space-y-5 rounded-2xl border border-line bg-card p-5 shadow-sm">
                        <legend class="px-2 text-sm font-bold">枠・効果</legend>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="input-border-width" class="mb-2 block text-sm font-semibold">入力欄の枠の太さ</label>
                                <div class="flex items-center gap-2">
                                    <input id="input-border-width" type="number" min="0" max="6" step="1" required
                                        class="min-w-0 w-full rounded-lg border border-line bg-field px-3 py-2">
                                    <span class="text-sm">px</span>
                                </div>
                            </div>
                            <div>
                                <label for="card-border-width" class="mb-2 block text-sm font-semibold">カードの枠の太さ</label>
                                <div class="flex items-center gap-2">
                                    <input id="card-border-width" type="number" min="0" max="6" step="1" required
                                        class="min-w-0 w-full rounded-lg border border-line bg-field px-3 py-2">
                                    <span class="text-sm">px</span>
                                </div>
                            </div>
                            <div>
                                <label for="button-border-width" class="mb-2 block text-sm font-semibold">ボタンの枠の太さ</label>
                                <div class="flex items-center gap-2">
                                    <input id="button-border-width" type="number" min="0" max="6" step="1" required
                                        class="min-w-0 w-full rounded-lg border border-line bg-field px-3 py-2">
                                    <span class="text-sm">px</span>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs">0〜6pxで指定できます。0pxでは枠を表示しません。</p>

                        <div>
                            <label class="flex items-center gap-3 text-sm font-semibold">
                                <input id="appearance-blur" type="checkbox" class="size-5 shrink-0 accent-progress">
                                背景のブラーを有効にする
                            </label>
                            <p class="mt-2 text-xs leading-5">ヘッダー・フッター・ダイアログの背面に適用します。</p>
                        </div>
                        <label class="flex items-center gap-3 text-sm font-semibold">
                            <input id="appearance-shadow" type="checkbox" class="size-5 shrink-0 accent-progress">
                            影を表示する
                        </label>
                    </fieldset>
                    <fieldset data-settings-category="fonts" class="rounded-2xl border border-line bg-card p-5 shadow-sm">
                        <legend class="px-2 text-sm font-bold">フォント</legend>
                        <div>
                            <label for="appearance-font" class="mb-2 block text-sm font-semibold">フォント</label>
                            <select id="appearance-font" class="w-full rounded-lg border border-line bg-field px-3 py-2">
                                <option value="default">標準（DM Sans / Noto Sans JP）</option>
                                <option value="sans">ゴシック体（Noto Sans JP）</option>
                                <option value="serif">明朝体（Noto Serif JP）</option>
                                <option value="rounded">丸ゴシック（Zen Maru Gothic）</option>
                                <option value="system">端末の標準フォント</option>
                            </select>
                        </div>
                    </fieldset>
                    <section data-settings-category="colors" class="space-y-3" aria-label="配色テンプレート">
                        <h3 class="font-bold">配色テンプレート</h3>
                        <p class="text-sm">選ぶと配色を切り替えて自動保存します。フォントや枠の設定は保持します。</p>
                        <div class="color-template-grid">
                            <?php foreach ($colorTemplates['templates'] as $template): ?>
                            <button type="button" class="color-template-button" data-color-template="<?= $template['id'] ?>" aria-pressed="false"
                                aria-label="<?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?>の配色を適用"
                                style="--template-text: <?= $template['colors']['text'] ?>; --template-primary: <?= $template['colors']['primary'] ?>; --template-secondary: <?= $template['colors']['secondary'] ?>;">
                                <span class="color-template-name"><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($template['id'] === $colorTemplates['default_id']): ?>
                                <span class="color-template-note">初期配色</span>
                                <?php endif; ?>
                                <span class="color-template-swatches" aria-hidden="true"><span></span><span></span><span></span></span>
                                <span data-template-status class="color-template-note"></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <div id="color-fields" data-settings-category="colors" class="space-y-6"></div>
                    <section data-settings-category="sharing" class="space-y-6" aria-label="カラーの共有">
                        <div class="space-y-3 rounded-2xl border border-line bg-card p-5 shadow-sm">
                            <h3 class="font-bold">エクスポート</h3>
                            <p class="text-sm leading-6">現在の配色を文字列としてコピーして共有できます。</p>
                            <label for="palette-export" class="block text-sm font-semibold">共有する配色文字列</label>
                            <textarea id="palette-export" rows="6" readonly class="w-full rounded-lg border border-line bg-field px-3 py-2 font-mono text-xs"></textarea>
                            <button id="copy-palette" type="button" class="rounded-lg bg-main px-4 py-2 text-sm font-semibold text-primary-ink">文字列をコピー</button>
                        </div>
                        <div class="space-y-3 rounded-2xl border border-line bg-card p-5 shadow-sm">
                            <h3 class="font-bold">インポート</h3>
                            <label for="palette-import" class="block text-sm font-semibold">共有された配色文字列</label>
                            <textarea id="palette-import" rows="6" placeholder="コピーした文字列をすべて貼り付けてください" class="w-full rounded-lg border border-line bg-field px-3 py-2 font-mono text-xs"></textarea>
                            <p class="text-sm leading-6">読み込んだ配色はアカウントに保存されます。</p>
                            <button id="import-palette" type="button" class="rounded-lg bg-main px-4 py-2 text-sm font-semibold text-primary-ink">配色をインポート</button>
                        </div>
                        <p id="palette-message" class="min-h-6 text-sm font-semibold" role="status"></p>
                    </section>
                    <p id="colors-message" class="min-h-6 text-sm font-semibold" role="status"></p>
                    <div class="settings-actions">
                        <button type="submit" class="rounded-lg bg-main px-4 py-3 text-sm font-semibold text-primary-ink disabled:opacity-50">今すぐ保存</button>
                        <div class="color-reset-actions">
                            <button id="cancel-colors" type="button" class="rounded-lg bg-accent px-4 py-3 text-sm font-semibold text-secondary-ink disabled:opacity-50">変更を取り消す</button>
                            <button id="reset-colors" data-settings-category="colors" type="button" disabled>カラーを初期設定に戻す</button>
                        </div>
                    </div>
                    <p id="color-reset-message" data-settings-category="colors" class="color-reset-feedback" role="status"></p>
                </form>
                <section data-settings-category="account" class="rounded-2xl border border-line bg-card p-5 shadow-sm" aria-label="アカウント情報">
                    <p class="text-xs font-semibold">ログイン中のアカウント</p>
                    <p id="settings-email" class="mt-2 break-all font-semibold"></p>
                    <button id="logout-button" type="button" class="mt-5 w-full rounded-xl border border-line px-4 py-3 text-sm font-semibold hover:bg-accent">ログアウト</button>
                </section>
            </section>

            <section id="debug-page" class="hidden space-y-6" aria-label="デバッグ情報">
                <div class="flex justify-end">
                    <button id="refresh-debug" type="button" class="rounded-lg bg-main px-4 py-2 text-sm font-semibold text-primary-ink disabled:opacity-50">情報を更新</button>
                </div>
                <p class="text-sm leading-6 text-ink">ログイン中のアカウントのデータを表示します。パスワードは一方向ハッシュで保存されているため、保存済みハッシュを表示します。</p>
                <div id="debug-content" class="space-y-6" aria-live="polite"></div>
            </section>
        </main>

        <button id="open-task-form" type="button" aria-label="TODOを追加"
            class="task-add-button fixed bottom-24 right-6 z-20 grid size-16 place-items-center rounded-full bg-main text-primary-ink transition hover:-translate-y-1 focus:outline-none focus:ring-4 focus:ring-accent sm:right-[calc(50%-15rem+1.5rem)]">
            <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 5v14M5 12h14" stroke-linecap="round"/>
            </svg>
        </button>

        <nav class="main-navigation fixed inset-x-0 bottom-0 z-20 mx-auto grid max-w-lg grid-cols-2 border-t border-line bg-navigation p-2" aria-label="メインナビゲーション">
            <button id="show-todo-page" type="button" class="flex items-center justify-center gap-2 rounded-xl bg-selected px-3 py-3 text-sm font-semibold text-ink">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" stroke-linecap="round"/>
                </svg>
                <span>TODO</span>
            </button>
            <button id="show-my-page" type="button" class="flex items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-medium text-ink">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0" stroke-linecap="round"/>
                </svg>
                <span>マイページ</span>
            </button>
        </nav>
    </div>

    <dialog id="task-dialog" class="w-[calc(100%-2rem)] max-w-md rounded-2xl bg-card p-0 text-ink shadow-2xl">
        <form id="task-form" class="space-y-4 p-6" novalidate>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold">新しいTODO</h2>
                <button id="close-task-form" type="button" aria-label="閉じる"
                    class="grid size-9 place-items-center rounded-full bg-accent text-xl text-secondary-ink">×</button>
            </div>

            <div>
                <label for="title" class="block font-medium">タイトル</label>
                <input id="title" name="title" type="text" required maxlength="255" aria-describedby="task-form-message"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label for="content" class="block font-medium">内容</label>
                <textarea id="content" name="content" maxlength="511" rows="3"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
            </div>

            <div>
                <label for="task-type" class="block font-medium">タスクの種類</label>
                <select id="task-type" name="task_type" aria-describedby="task-type-help"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2">
                    <option value="unscheduled">日時なし</option>
                    <option value="deadline">期限のみ</option>
                    <option value="period">開始・終了あり</option>
                </select>
                <p id="task-type-help" data-task-type-help class="mt-1 text-xs">開始・締切を指定せずに登録します。</p>
            </div>

            <div data-start-field hidden>
                <label for="starts-at" class="block font-medium">開始日時</label>
                <input id="starts-at" name="starts_at" type="datetime-local" disabled aria-describedby="task-form-message"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div data-deadline-field hidden>
                <label for="scheduled-at" data-deadline-label class="block font-medium">締切日時</label>
                <input id="scheduled-at" name="scheduled_at" type="datetime-local" disabled aria-describedby="task-form-message"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <p id="task-form-message" data-task-form-message class="min-h-6 text-sm font-semibold" role="status">タイトルを入力してください。空白のみでは登録できません。</p>

            <button type="submit" disabled class="task-submit-button w-full rounded-lg bg-main px-4 py-3 font-semibold text-primary-ink">
                追加する
            </button>
        </form>
    </dialog>

    <dialog id="edit-task-dialog" class="w-[calc(100%-2rem)] max-w-md rounded-2xl bg-card p-0 text-ink shadow-2xl">
        <form id="edit-task-form" class="space-y-4 p-6" novalidate>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold">TODOを編集</h2>
                <button id="close-edit-task-form" type="button" aria-label="閉じる"
                    class="grid size-9 place-items-center rounded-full bg-accent text-xl text-secondary-ink">×</button>
            </div>

            <input id="edit-id" name="id" type="hidden">

            <div>
                <label for="edit-title" class="block font-medium">タイトル</label>
                <input id="edit-title" name="title" type="text" required maxlength="255" aria-describedby="edit-task-form-message"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label for="edit-content" class="block font-medium">内容</label>
                <textarea id="edit-content" name="content" maxlength="511" rows="3"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
            </div>

            <div>
                <label for="edit-task-type" class="block font-medium">タスクの種類</label>
                <select id="edit-task-type" name="task_type" aria-describedby="edit-task-type-help"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2">
                    <option value="unscheduled">日時なし</option>
                    <option value="deadline">期限のみ</option>
                    <option value="period">開始・終了あり</option>
                </select>
                <p id="edit-task-type-help" data-task-type-help class="mt-1 text-xs">開始・締切を指定せずに登録します。</p>
            </div>

            <div data-start-field hidden>
                <label for="edit-starts-at" class="block font-medium">開始日時</label>
                <input id="edit-starts-at" name="starts_at" type="datetime-local" disabled aria-describedby="edit-task-form-message"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div data-deadline-field hidden>
                <label for="edit-scheduled-at" data-deadline-label class="block font-medium">締切日時</label>
                <input id="edit-scheduled-at" name="scheduled_at" type="datetime-local" disabled aria-describedby="edit-task-form-message"
                    class="mt-1 w-full rounded-lg border border-line bg-field px-3 py-2 focus:border-main focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <p id="edit-task-form-message" data-task-form-message class="min-h-6 text-sm font-semibold" role="status"></p>

            <button type="submit" class="task-submit-button w-full rounded-lg bg-main px-4 py-3 font-semibold text-primary-ink">
                変更を保存
            </button>
        </form>
    </dialog>
</body>
</html>
