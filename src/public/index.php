<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f4f1ea">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Custom ToDo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { canvas: '#f4f1ea', ink: '#1d1d1b', accent: '#e65d3f', muted: '#77746d' },
                    fontFamily: { sans: ['DM Sans', 'Noto Sans JP', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        summary::-webkit-details-marker { display: none; }
        dialog::backdrop { background: rgb(29 29 27 / 45%); }
        .progress-range {
            appearance: none;
            height: 0.55rem;
            border-radius: 9999px;
            background: linear-gradient(to right, #e65d3f 0 var(--progress), #e7e5e4 var(--progress) 100%);
        }
        .progress-range::-webkit-slider-thumb {
            appearance: none;
            width: 1.35rem;
            height: 1.35rem;
            border: 3px solid white;
            border-radius: 9999px;
            background: #e65d3f;
            box-shadow: 0 2px 8px rgb(29 29 27 / 25%);
        }
        .progress-range::-moz-range-thumb {
            width: 1.1rem;
            height: 1.1rem;
            border: 3px solid white;
            border-radius: 9999px;
            background: #e65d3f;
            box-shadow: 0 2px 8px rgb(29 29 27 / 25%);
        }
    </style>
    <script src="/assets/app.js" defer></script>
</head>
<body class="min-h-screen bg-stone-200 font-sans text-ink">
    <section id="auth-view" class="mx-auto flex min-h-screen max-w-lg items-center bg-canvas px-6 py-12 shadow-xl">
        <div class="w-full">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-accent">Custom ToDo</p>
            <h1 class="mt-2 text-3xl font-bold">おかえりなさい</h1>
            <p class="mt-2 text-sm text-muted">メールアドレスとパスワードでログインしてください。</p>

            <div class="mt-8 grid grid-cols-2 rounded-xl bg-stone-200 p-1">
                <button id="show-login" type="button" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold shadow-sm">ログイン</button>
                <button id="show-register" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-muted">新規登録</button>
            </div>

            <form id="login-form" class="mt-6 space-y-4 rounded-2xl bg-white p-6 shadow-sm">
                <div>
                    <label for="login-email" class="block text-sm font-semibold">メールアドレス</label>
                    <input id="login-email" name="email" type="email" autocomplete="email" required
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
                </div>
                <div>
                    <label for="login-password" class="block text-sm font-semibold">パスワード</label>
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
                </div>
                <button type="submit" class="w-full rounded-lg bg-ink px-4 py-3 font-semibold text-white">ログイン</button>
            </form>

            <form id="register-form" class="mt-6 hidden space-y-4 rounded-2xl bg-white p-6 shadow-sm">
                <div>
                    <label for="register-email" class="block text-sm font-semibold">メールアドレス</label>
                    <input id="register-email" name="email" type="email" autocomplete="email" required
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
                </div>
                <div>
                    <label for="register-password" class="block text-sm font-semibold">パスワード</label>
                    <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="8" required
                        class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-3 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
                    <p class="mt-1 text-xs text-muted">8文字以上で入力してください。</p>
                </div>
                <button type="submit" class="w-full rounded-lg bg-accent px-4 py-3 font-semibold text-white">アカウントを作成</button>
            </form>

            <p id="auth-message" class="mt-4 min-h-6 text-sm text-red-700" role="status"></p>
        </div>
    </section>

    <div id="app-shell" class="mx-auto hidden min-h-screen max-w-lg bg-canvas shadow-xl">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-stone-200 bg-canvas px-5 py-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-muted">Custom ToDo</p>
                <h1 id="page-title" class="mt-1 text-2xl font-bold">今日のタスク</h1>
            </div>
            <div class="text-right">
                <p id="current-user-email" class="max-w-40 truncate text-xs text-muted"></p>
            </div>
        </header>

        <main class="px-5 pb-36 pt-6">
            <p id="message" class="mb-4 min-h-6 text-sm" role="status"></p>

            <section id="todo-page" aria-labelledby="task-list-heading">
                <div class="mb-4 flex items-end justify-between">
                    <h2 id="task-list-heading" class="text-lg font-bold">TODOリスト</h2>
                    <span id="task-count" class="text-sm text-muted"></span>
                </div>
                <ul id="task-list" class="space-y-4"></ul>
            </section>

            <section id="my-page" class="hidden space-y-8" aria-labelledby="completed-list-heading">
                <div>
                    <div class="mb-4 flex items-end justify-between">
                        <h2 id="completed-list-heading" class="text-lg font-bold">完了したタスク</h2>
                        <span id="completed-task-count" class="text-sm text-muted"></span>
                    </div>
                    <ul id="completed-task-list" class="space-y-3"></ul>
                </div>

                <section aria-labelledby="settings-heading">
                    <h2 id="settings-heading" class="mb-4 text-lg font-bold">設定</h2>
                    <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted">ログイン中のアカウント</p>
                        <p id="settings-email" class="mt-2 break-all font-semibold"></p>
                        <button id="logout-button" type="button"
                            class="mt-5 w-full rounded-xl border border-red-200 px-4 py-3 text-sm font-semibold text-red-700 hover:bg-red-50">
                            ログアウト
                        </button>
                    </div>
                </section>
            </section>
        </main>

        <button id="open-task-form" type="button" aria-label="TODOを追加"
            class="fixed bottom-24 right-6 z-20 grid size-16 place-items-center rounded-full bg-gradient-to-br from-orange-400 to-accent text-white shadow-[0_12px_30px_rgb(230_93_63_/_35%)] transition hover:-translate-y-1 focus:outline-none focus:ring-4 focus:ring-orange-200 sm:right-[calc(50%-15rem+1.5rem)]">
            <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 5v14M5 12h14" stroke-linecap="round"/>
            </svg>
        </button>

        <nav class="fixed inset-x-0 bottom-0 z-20 mx-auto grid max-w-lg grid-cols-2 border-t border-stone-200 bg-white p-2 shadow-[0_-6px_24px_rgb(29_29_27_/_10%)]" aria-label="メインナビゲーション">
            <button id="show-todo-page" type="button" class="flex items-center justify-center gap-2 rounded-xl bg-orange-50 px-3 py-3 text-sm font-semibold text-accent">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" stroke-linecap="round"/>
                </svg>
                <span>TODO</span>
            </button>
            <button id="show-my-page" type="button" class="flex items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-medium text-muted">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0" stroke-linecap="round"/>
                </svg>
                <span>マイページ</span>
            </button>
        </nav>
    </div>

    <dialog id="task-dialog" class="w-[calc(100%-2rem)] max-w-md rounded-2xl bg-white p-0 shadow-2xl">
        <form id="task-form" class="space-y-4 p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold">新しいTODO</h2>
                <button id="close-task-form" type="button" aria-label="閉じる"
                    class="grid size-9 place-items-center rounded-full bg-stone-100 text-xl">×</button>
            </div>

            <div>
                <label for="title" class="block font-medium">タイトル</label>
                <input id="title" name="title" type="text" required maxlength="255"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
            </div>

            <div>
                <label for="content" class="block font-medium">内容</label>
                <textarea id="content" name="content" maxlength="511" rows="3"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100"></textarea>
            </div>

            <div>
                <label for="scheduled-at" class="block font-medium">予定日時</label>
                <input id="scheduled-at" name="scheduled_at" type="datetime-local"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
            </div>

            <button type="submit" class="w-full rounded-lg bg-accent px-4 py-3 font-semibold text-white">
                追加する
            </button>
        </form>
    </dialog>

    <dialog id="edit-task-dialog" class="w-[calc(100%-2rem)] max-w-md rounded-2xl bg-white p-0 shadow-2xl">
        <form id="edit-task-form" class="space-y-4 p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold">TODOを編集</h2>
                <button id="close-edit-task-form" type="button" aria-label="閉じる"
                    class="grid size-9 place-items-center rounded-full bg-stone-100 text-xl">×</button>
            </div>

            <input id="edit-id" name="id" type="hidden">

            <div>
                <label for="edit-title" class="block font-medium">タイトル</label>
                <input id="edit-title" name="title" type="text" required maxlength="255"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
            </div>

            <div>
                <label for="edit-content" class="block font-medium">内容</label>
                <textarea id="edit-content" name="content" maxlength="511" rows="3"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100"></textarea>
            </div>

            <div>
                <label for="edit-scheduled-at" class="block font-medium">予定日時</label>
                <input id="edit-scheduled-at" name="scheduled_at" type="datetime-local"
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 focus:border-accent focus:outline-none focus:ring-2 focus:ring-orange-100">
            </div>

            <button type="submit" class="w-full rounded-lg bg-ink px-4 py-3 font-semibold text-white">
                変更を保存
            </button>
        </form>
    </dialog>
</body>
</html>
