const taskForm = document.querySelector('#task-form');
const taskList = document.querySelector('#task-list');
const message = document.querySelector('#message');
const taskCount = document.querySelector('#task-count');
const taskDialog = document.querySelector('#task-dialog');
const openTaskFormButton = document.querySelector('#open-task-form');
const closeTaskFormButton = document.querySelector('#close-task-form');
const editTaskDialog = document.querySelector('#edit-task-dialog');
const editTaskForm = document.querySelector('#edit-task-form');
const closeEditTaskFormButton = document.querySelector('#close-edit-task-form');
const authView = document.querySelector('#auth-view');
const appShell = document.querySelector('#app-shell');
const loginForm = document.querySelector('#login-form');
const registerForm = document.querySelector('#register-form');
const authMessage = document.querySelector('#auth-message');
const showLoginButton = document.querySelector('#show-login');
const showRegisterButton = document.querySelector('#show-register');
const currentUserEmail = document.querySelector('#current-user-email');
const logoutButton = document.querySelector('#logout-button');
const settingsEmail = document.querySelector('#settings-email');
const todoPage = document.querySelector('#todo-page');
const myPage = document.querySelector('#my-page');
const pageTitle = document.querySelector('#page-title');
const showTodoPageButton = document.querySelector('#show-todo-page');
const showMyPageButton = document.querySelector('#show-my-page');
const completedTaskList = document.querySelector('#completed-task-list');
const completedTaskCount = document.querySelector('#completed-task-count');
const archivePage = document.querySelector('#archive-page');
const archiveSearch = document.querySelector('#archive-search');
const archiveDate = document.querySelector('#archive-date');
const clearArchiveFiltersButton = document.querySelector('#clear-archive-filters');
const registerPassword = document.querySelector('#register-password');
const registerPasswordConfirmation = document.querySelector('#register-password-confirmation');
const debugPage = document.querySelector('#debug-page');
const debugContent = document.querySelector('#debug-content');
const refreshDebugButton = document.querySelector('#refresh-debug');
const settingsPage = document.querySelector('#settings-page');
const settingsDetailPage = document.querySelector('#settings-detail-page');
let completedTasks = [];
let isReordering = false;
let dragState = null;
let currentPage = 'todo';
let pageRequestId = 0;

function showAuthMessage(text) {
    authMessage.textContent = text;
}

function showAuthView() {
    appShell.classList.add('hidden');
    authView.classList.remove('hidden');
}

async function showApp(user) {
    currentUserEmail.textContent = user.email;
    settingsEmail.textContent = user.email;
    let settingsError = null;
    try {
        await loadUserColors(user.id);
    } catch (error) {
        settingsError = error;
        colorsMessage.textContent = `${error.message} ページを再読み込みしてください。`;
    }
    authView.classList.add('hidden');
    appShell.classList.remove('hidden');
    await activateCurrentPage();
    if (settingsError) showMessage(settingsError.message, true);
}

function showPage(page) {
    currentPage = page;
    pageRequestId++;
    const isTodoPage = page === 'todo';
    todoPage.classList.toggle('hidden', !isTodoPage);
    myPage.classList.toggle('hidden', page !== 'my');
    archivePage.classList.toggle('hidden', page !== 'archive');
    debugPage.classList.toggle('hidden', page !== 'debug');
    settingsPage.classList.toggle('hidden', page !== 'settings');
    settingsDetailPage.classList.toggle('hidden', page !== 'settings-detail');
    openTaskFormButton.classList.toggle('hidden', !isTodoPage);
    pageTitle.textContent = { todo: '今日のタスク', my: 'マイページ', archive: 'アーカイブ', debug: 'デバッグ', settings: '設定', 'settings-detail': '設定' }[page];
    const navigationButtons = { todo: showTodoPageButton, my: showMyPageButton };
    const selectedNavigation = page === 'todo' ? 'todo' : 'my';
    for (const [name, button] of Object.entries(navigationButtons)) {
        button.className = name === selectedNavigation
            ? 'flex items-center justify-center gap-2 rounded-xl bg-selected px-3 py-3 text-sm font-semibold text-ink'
            : 'flex items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-medium text-ink';
        if (name === selectedNavigation) {
            button.setAttribute('aria-current', 'page');
        } else {
            button.removeAttribute('aria-current');
        }
    }
    showMessage('');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function selectAuthForm(type) {
    const isLogin = type === 'login';
    loginForm.classList.toggle('hidden', !isLogin);
    registerForm.classList.toggle('hidden', isLogin);
    showLoginButton.className = isLogin
        ? 'rounded-lg bg-card px-3 py-2 text-sm font-semibold shadow-sm'
        : 'rounded-lg px-3 py-2 text-sm font-semibold text-ink';
    showRegisterButton.className = isLogin
        ? 'rounded-lg px-3 py-2 text-sm font-semibold text-ink'
        : 'rounded-lg bg-card px-3 py-2 text-sm font-semibold shadow-sm';
    showAuthMessage('');
}

function showMessage(text, isError = false) {
    message.textContent = text;
    message.className = `mb-4 min-h-6 text-sm text-ink ${isError ? 'font-semibold' : ''}`;
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, options);
    const result = await response.json();

    if (!response.ok) {
        throw new Error(result.error ?? 'APIの呼び出しに失敗しました。');
    }

    return result;
}

async function authenticate(url, form) {
    const formData = new FormData(form);
    const result = await requestJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: formData.get('email'),
            password: formData.get('password'),
            ...(form === registerForm ? { password_confirmation: formData.get('password_confirmation') } : {}),
        }),
    });

    form.reset();
    await showApp(result.data);
    await getTasks();
}

function parseDatabaseDate(value) {
    if (typeof value !== 'string') {
        return new Date(NaN);
    }
    return new Date(value.replace(' ', 'T')
        .replace(/(\.\d{3})\d+/, '$1')
        .replace(/([+-]\d{2})$/, '$1:00'));
}

function toDateTimeLocalValue(value) {
    if (!value) {
        return '';
    }

    const date = parseDatabaseDate(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
        + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function createTaskElement(task, total) {
    const item = document.createElement('li');
    item.dataset.taskId = task.id;
    const details = document.createElement('details');
    details.className = 'group overflow-hidden rounded-2xl border border-line bg-card shadow-sm';

    const summary = document.createElement('summary');
    summary.className = 'flex cursor-pointer list-none items-center gap-3 px-4 py-5';

    const completeCheckbox = document.createElement('input');
    completeCheckbox.type = 'checkbox';
    completeCheckbox.setAttribute('aria-label', `${task.title}を完了する`);
    completeCheckbox.className = 'size-6 shrink-0 cursor-pointer rounded border-line accent-progress';
    completeCheckbox.addEventListener('click', (event) => {
        event.stopPropagation();
    });
    completeCheckbox.addEventListener('change', () => completeTask(task.id));

    const title = document.createElement('h3');
    title.className = 'min-w-0 flex-1 truncate text-lg font-semibold';
    title.textContent = task.title;

    const chevron = document.createElement('span');
    chevron.className = 'grid size-9 shrink-0 place-items-center rounded-full bg-accent text-secondary-ink transition-all group-open:rotate-180';
    chevron.setAttribute('aria-hidden', 'true');
    chevron.innerHTML = '<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    const dragHandle = document.createElement('button');
    dragHandle.type = 'button';
    dragHandle.className = 'task-drag-handle grid size-10 shrink-0 place-items-center rounded-lg bg-transparent text-ink disabled:cursor-not-allowed disabled:opacity-30';
    dragHandle.setAttribute('aria-label', `${task.title}の並べ替えハンドル`);
    dragHandle.setAttribute('aria-describedby', 'task-order-help');
    dragHandle.dataset.dragHandle = task.id;
    dragHandle.disabled = total < 2 || isReordering;
    dragHandle.innerHTML = '<svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>';
    dragHandle.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
    });
    dragHandle.addEventListener('pointerdown', (event) => startTaskDrag(event, item, dragHandle));
    dragHandle.addEventListener('keydown', (event) => moveTaskWithKeyboard(event, item));

    summary.append(completeCheckbox, title, dragHandle, chevron);

    const detailArea = document.createElement('div');
    detailArea.className = 'border-t border-line px-5 pb-5 pt-4';

    if (task.content) {
        const content = document.createElement('p');
        content.className = 'whitespace-pre-wrap text-sm leading-6 text-ink';
        content.textContent = task.content;
        detailArea.append(content);
    }

    if (task.scheduled_at) {
        const scheduledAt = document.createElement('p');
        scheduledAt.className = 'mt-3 text-sm font-semibold text-ink';
        const date = parseDatabaseDate(task.scheduled_at);
        scheduledAt.textContent = `予定: ${date.toLocaleString('ja-JP', {
            dateStyle: 'medium',
            timeStyle: 'short',
        })}`;
        detailArea.append(scheduledAt);
    }

    const progressArea = document.createElement('div');
    progressArea.className = 'mt-5 rounded-xl bg-accent p-4';

    const progressHeader = document.createElement('div');
    progressHeader.className = 'mb-3 flex items-center justify-between';

    const progressLabel = document.createElement('label');
    progressLabel.className = 'text-sm font-semibold';
    progressLabel.textContent = '進捗';
    progressLabel.htmlFor = `progress-${task.id}`;

    const progressValue = document.createElement('output');
    progressValue.className = 'text-sm font-bold text-ink';
    progressValue.htmlFor = `progress-${task.id}`;
    progressValue.textContent = `${task.progress ?? 0}%`;

    const progressInput = document.createElement('input');
    progressInput.id = `progress-${task.id}`;
    progressInput.type = 'range';
    progressInput.min = '0';
    progressInput.max = '100';
    progressInput.step = '1';
    progressInput.value = task.progress ?? 0;
    progressInput.className = 'progress-range block w-full cursor-pointer';
    progressInput.style.setProperty('--progress', `${progressInput.value}%`);
    progressInput.addEventListener('input', () => {
        progressValue.textContent = `${progressInput.value}%`;
        progressInput.style.setProperty('--progress', `${progressInput.value}%`);
    });

    const updateButton = document.createElement('button');
    updateButton.type = 'button';
    updateButton.className = 'mt-4 w-full rounded-lg bg-main px-3 py-2 text-sm font-semibold text-primary-ink';
    updateButton.textContent = '進捗を保存';
    updateButton.addEventListener('click', () => {
        updateTask(task.id, Number(progressInput.value));
    });

    progressHeader.append(progressLabel, progressValue);
    progressArea.append(progressHeader, progressInput, updateButton);

    const actionArea = document.createElement('div');
    actionArea.className = 'mt-4 flex justify-start gap-2';

    const editButton = document.createElement('button');
    editButton.type = 'button';
    editButton.className = 'rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-secondary-ink';
    editButton.textContent = '編集';
    editButton.addEventListener('click', () => openEditDialog(task));

    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'rounded-lg px-3 py-2 text-sm font-medium text-ink hover:bg-accent';
    deleteButton.textContent = '削除';
    deleteButton.addEventListener('click', () => deleteTask(task.id));

    actionArea.append(editButton, deleteButton);
    detailArea.append(progressArea, actionArea);
    details.append(summary, detailArea);
    item.append(details);
    return item;
}

async function getTasks() {
    const result = await requestJson('/api/get_tasks.php');
    if (dragState) {
        cancelTaskDrag({});
    }
    taskList.replaceChildren();
    taskCount.textContent = `${result.data.length}件`;

    if (result.data.length === 0) {
        const emptyItem = document.createElement('li');
        emptyItem.className = 'rounded-2xl border border-dashed border-line bg-card p-8 text-center text-ink';
        emptyItem.textContent = 'TODOはまだありません。';
        taskList.append(emptyItem);
        return;
    }

    taskList.append(...result.data.map((task) => createTaskElement(task, result.data.length)));
}

function taskOrder() {
    return [...taskList.querySelectorAll('[data-task-id]')].map((item) => Number(item.dataset.taskId));
}

function restoreTaskOrder(ids) {
    for (const id of ids) {
        const item = taskList.querySelector(`[data-task-id="${id}"]`);
        if (item) {
            taskList.append(item);
        }
    }
}

async function saveTaskOrder(originalOrder, movedId) {
    if (isReordering) {
        return;
    }
    isReordering = true;
    taskList.querySelectorAll('[data-drag-handle]').forEach((button) => { button.disabled = true; });
    try {
        await requestJson('/api/reorder_task.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_ids: taskOrder() }),
        });
        await getTasks();
        showMessage('並び順を保存しました。');
    } catch (error) {
        restoreTaskOrder(originalOrder);
        // 他の画面で追加・完了された場合も、DBの最新状態に戻す。
        try {
            await getTasks();
        } catch {
            // 通信できない場合は、保存前の表示順を保つ。
        }
        showMessage(error.message, true);
    } finally {
        isReordering = false;
        taskList.querySelectorAll('[data-drag-handle]').forEach((button) => {
            button.disabled = taskOrder().length < 2;
        });
        const button = taskList.querySelector(`[data-drag-handle="${movedId}"]`);
        if (button && !button.disabled) {
            button.focus();
        }
    }
}

function moveTaskWithKeyboard(event, item) {
    if (!['ArrowUp', 'ArrowDown'].includes(event.key) || isReordering || dragState) {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
    const originalOrder = taskOrder();
    const neighbor = event.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling;
    if (!neighbor) {
        return;
    }
    taskList.insertBefore(item, event.key === 'ArrowUp' ? neighbor : neighbor.nextElementSibling);
    saveTaskOrder(originalOrder, Number(item.dataset.taskId));
}

function startTaskDrag(event, item, handle) {
    if (event.button !== 0 || !event.isPrimary || isReordering || dragState) {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
    const rectangle = item.getBoundingClientRect();
    dragState = {
        item, handle, pointerId: event.pointerId, originalOrder: taskOrder(),
        startY: event.clientY, pointerY: event.clientY, offsetY: event.clientY - rectangle.top,
        preview: null, scrollFrame: null, previousUserSelect: document.body.style.userSelect,
    };
    handle.setPointerCapture(event.pointerId);
    document.addEventListener('pointermove', moveTaskDrag);
    document.addEventListener('pointerup', finishTaskDrag);
    document.addEventListener('pointercancel', cancelTaskDrag);
    document.addEventListener('keydown', cancelTaskDragWithEscape);
}

function moveTaskDrag(event) {
    if (!dragState || event.pointerId !== dragState.pointerId) {
        return;
    }
    dragState.pointerY = event.clientY;
    if (!dragState.preview && Math.abs(event.clientY - dragState.startY) < 5) {
        return;
    }
    if (!dragState.preview) {
        const rectangle = dragState.item.getBoundingClientRect();
        const preview = dragState.item.cloneNode(true);
        preview.className = 'task-drag-preview';
        preview.setAttribute('aria-hidden', 'true');
        preview.inert = true;
        // プレビューの入力欄は、元のタスクと同じIDを持たせない。
        preview.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
        preview.style.left = `${rectangle.left}px`;
        preview.style.width = `${rectangle.width}px`;
        document.body.append(preview);
        dragState.preview = preview;
        dragState.item.classList.add('task-drag-placeholder');
        document.body.style.userSelect = 'none';
        dragState.scrollFrame = requestAnimationFrame(scrollDuringTaskDrag);
    }
    dragState.preview.style.top = `${event.clientY - dragState.offsetY}px`;
    placeDraggedTask();
}

function placeDraggedTask() {
    const { item, pointerY } = dragState;
    const nextItem = [...taskList.children].find((candidate) => {
        if (candidate === item) {
            return false;
        }
        const rectangle = candidate.getBoundingClientRect();
        return pointerY < rectangle.top + rectangle.height / 2;
    });
    if (item.nextElementSibling !== (nextItem ?? null)) {
        taskList.insertBefore(item, nextItem ?? null);
        // DOM内で移動したハンドルも引き続きポインターを追跡する。
        dragState.handle.setPointerCapture(dragState.pointerId);
    }
}

function scrollDuringTaskDrag() {
    if (!dragState?.preview) {
        return;
    }
    const topEdge = document.querySelector('header').getBoundingClientRect().bottom + 50;
    const bottomEdge = document.querySelector('nav').getBoundingClientRect().top - 50;
    const y = dragState.pointerY;
    const distance = y < topEdge ? -Math.min(16, (topEdge - y) / 4)
        : y > bottomEdge ? Math.min(16, (y - bottomEdge) / 4) : 0;
    if (distance !== 0) {
        window.scrollBy(0, distance);
        placeDraggedTask();
    }
    dragState.scrollFrame = requestAnimationFrame(scrollDuringTaskDrag);
}

function endTaskDrag() {
    const state = dragState;
    dragState = null;
    document.removeEventListener('pointermove', moveTaskDrag);
    document.removeEventListener('pointerup', finishTaskDrag);
    document.removeEventListener('pointercancel', cancelTaskDrag);
    document.removeEventListener('keydown', cancelTaskDragWithEscape);
    cancelAnimationFrame(state.scrollFrame);
    state.preview?.remove();
    state.item.classList.remove('task-drag-placeholder');
    document.body.style.userSelect = state.previousUserSelect;
    if (state.handle.hasPointerCapture(state.pointerId)) {
        state.handle.releasePointerCapture(state.pointerId);
    }
    return state;
}

function finishTaskDrag(event) {
    if (!dragState || event.pointerId !== dragState.pointerId) {
        return;
    }
    const state = endTaskDrag();
    if (taskOrder().join(',') !== state.originalOrder.join(',')) {
        saveTaskOrder(state.originalOrder, Number(state.item.dataset.taskId));
    }
}

function cancelTaskDrag(event) {
    if (!dragState || (event.pointerId !== undefined && event.pointerId !== dragState.pointerId)) {
        return;
    }
    const state = endTaskDrag();
    restoreTaskOrder(state.originalOrder);
}

function cancelTaskDragWithEscape(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        cancelTaskDrag(event);
    }
}

function createCompletedTaskElement(task) {
    const item = document.createElement('li');
    item.className = 'rounded-2xl border border-line bg-card p-5 shadow-sm';

    const heading = document.createElement('div');
    heading.className = 'flex items-start gap-3';

    const checkIcon = document.createElement('span');
    checkIcon.className = 'grid size-7 shrink-0 place-items-center rounded-full bg-main text-primary-ink';
    checkIcon.innerHTML = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    const textArea = document.createElement('div');
    textArea.className = 'min-w-0';
    const title = document.createElement('h3');
    title.className = 'font-semibold text-ink line-through';
    title.textContent = task.title;
    textArea.append(title);

    if (task.content) {
        const content = document.createElement('p');
        content.className = 'mt-1 text-sm text-ink';
        content.textContent = task.content;
        textArea.append(content);
    }

    const completedAt = document.createElement('p');
    completedAt.className = 'mt-3 text-xs font-medium text-ink';
    completedAt.textContent = `完了: ${parseDatabaseDate(task.completed_at ?? task.updated_at).toLocaleString('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
    })}`;
    textArea.append(completedAt);
    heading.append(checkIcon, textArea);
    const restoreButton = document.createElement('button');
    restoreButton.type = 'button';
    restoreButton.className = 'mt-4 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-secondary-ink disabled:opacity-50';
    restoreButton.textContent = 'TODOに戻す';
    restoreButton.setAttribute('aria-label', `${task.title}をTODOに戻す`);
    restoreButton.addEventListener('click', () => restoreTask(task.id, restoreButton));
    item.append(heading, restoreButton);

    return item;
}

async function getCompletedTasks(requestId) {
    const result = await requestJson('/api/get_completed_tasks.php');
    if (requestId !== pageRequestId || appShell.classList.contains('hidden')) {
        return;
    }
    completedTasks = result.data;
    renderCompletedTasks();
}

function renderCompletedTasks() {
    const keyword = archiveSearch.value.trim().normalize('NFKC').toLocaleLowerCase('ja-JP');
    const filteredTasks = completedTasks.filter((task) => {
        const text = `${task.title}\n${task.content ?? ''}`.normalize('NFKC').toLocaleLowerCase('ja-JP');
        const date = toDateTimeLocalValue(task.completed_at ?? task.updated_at).slice(0, 10);
        return text.includes(keyword) && (!archiveDate.value || date === archiveDate.value);
    });
    completedTaskList.replaceChildren();
    completedTaskCount.textContent = `${filteredTasks.length}件 / 全${completedTasks.length}件`;

    if (filteredTasks.length === 0) {
        const emptyItem = document.createElement('p');
        emptyItem.className = 'rounded-2xl border border-dashed border-line bg-card p-8 text-center text-ink';
        emptyItem.textContent = completedTasks.length === 0
            ? '完了したTODOはまだありません。'
            : '検索条件に一致するTODOはありません。';
        completedTaskList.append(emptyItem);
        return;
    }

    // ブラウザの現地時刻で完了日を揃え、日付ごとにまとめる。
    const groups = new Map();
    for (const task of filteredTasks) {
        const date = toDateTimeLocalValue(task.completed_at ?? task.updated_at).slice(0, 10);
        if (!groups.has(date)) {
            groups.set(date, []);
        }
        groups.get(date).push(task);
    }
    for (const date of [...groups.keys()].sort().reverse()) {
        const section = document.createElement('section');
        const heading = document.createElement('h3');
        heading.className = 'mb-3 text-sm font-bold text-ink';
        const dateLabel = date ? new Date(`${date}T00:00:00`).toLocaleDateString('ja-JP', { dateStyle: 'long' }) : '日付不明';
        heading.textContent = `${dateLabel}（${groups.get(date).length}件）`;
        const list = document.createElement('ul');
        list.className = 'space-y-3';
        list.append(...groups.get(date).map(createCompletedTaskElement));
        section.append(heading, list);
        completedTaskList.append(section);
    }
}

async function restoreTask(id, button) {
    button.disabled = true;
    try {
        await requestJson('/api/restore_task.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        // 復元に成功したタスクは、再取得に失敗してもアーカイブに残さない。
        completedTasks = completedTasks.filter((task) => task.id !== id);
        renderCompletedTasks();
        await getTasks();
        showMessage('TODOの末尾に戻しました。進捗は0%です。');
    } catch (error) {
        showMessage(error.message, true);
    } finally {
        button.disabled = false;
    }
}

async function addTask(title, content, scheduledAt) {
    await requestJson('/api/add_task.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title, content, scheduled_at: scheduledAt }),
    });
}

async function updateTask(id, progress) {
    try {
        await requestJson('/api/update_task.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, progress }),
        });
        showMessage(progress === 100 ? 'TODOを完了しました。' : '進捗率を更新しました。');
        await getTasks();
    } catch (error) {
        showMessage(error.message, true);
    }
}

async function editTask(id, title, content, scheduledAt) {
    await requestJson('/api/edit_task.php', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, title, content, scheduled_at: scheduledAt }),
    });
}

function openEditDialog(task) {
    document.querySelector('#edit-id').value = task.id;
    document.querySelector('#edit-title').value = task.title;
    document.querySelector('#edit-content').value = task.content ?? '';
    document.querySelector('#edit-scheduled-at').value = toDateTimeLocalValue(task.scheduled_at);
    editTaskDialog.showModal();
    document.querySelector('#edit-title').focus();
}

async function completeTask(id) {
    try {
        await requestJson('/api/complete_task.php', {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        showMessage('TODOを完了しました。');
        await getTasks();
    } catch (error) {
        showMessage(error.message, true);
    }
}

async function deleteTask(id) {
    try {
        await requestJson('/api/delete_task.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        });
        showMessage('TODOを削除しました。');
        await getTasks();
    } catch (error) {
        showMessage(error.message, true);
    }
}

taskForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(taskForm);
    const scheduledAtInput = formData.get('scheduled_at');
    const scheduledAt = scheduledAtInput
        ? new Date(scheduledAtInput).toISOString()
        : null;

    try {
        await addTask(formData.get('title'), formData.get('content'), scheduledAt);
        taskForm.reset();
        taskDialog.close();
        showMessage('TODOを追加しました。');
        await getTasks();
    } catch (error) {
        showMessage(error.message, true);
    }
});

editTaskForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(editTaskForm);
    const scheduledAtInput = formData.get('scheduled_at');
    const scheduledAt = scheduledAtInput
        ? new Date(scheduledAtInput).toISOString()
        : null;

    try {
        await editTask(
            Number(formData.get('id')),
            formData.get('title'),
            formData.get('content'),
            scheduledAt,
        );
        editTaskDialog.close();
        showMessage('TODOを編集しました。');
        await getTasks();
    } catch (error) {
        showMessage(error.message, true);
    }
});

openTaskFormButton.addEventListener('click', () => {
    taskDialog.showModal();
    document.querySelector('#title').focus();
});

closeTaskFormButton.addEventListener('click', () => {
    taskDialog.close();
});

taskDialog.addEventListener('click', (event) => {
    if (event.target === taskDialog) {
        taskDialog.close();
    }
});

closeEditTaskFormButton.addEventListener('click', () => {
    editTaskDialog.close();
});

editTaskDialog.addEventListener('click', (event) => {
    if (event.target === editTaskDialog) {
        editTaskDialog.close();
    }
});

showLoginButton.addEventListener('click', () => selectAuthForm('login'));
showRegisterButton.addEventListener('click', () => selectAuthForm('register'));
showTodoPageButton.addEventListener('click', () => navigateToPage('todo'));
showMyPageButton.addEventListener('click', () => navigateToPage('my'));

function navigateToPage(page) {
    if (window.location.hash === `#${page}`) {
        activateCurrentPage();
    } else {
        window.location.hash = page;
    }
}

async function activateCurrentPage() {
    if (appShell.classList.contains('hidden')) {
        return;
    }
    if (dragState) {
        cancelTaskDrag({});
    }
    const route = window.location.hash.slice(1);
    const category = route === 'colors' ? 'colors' : route.startsWith('settings/') ? route.slice(9) : null;
    if (['colors', 'effects', 'fonts', 'sharing', 'account'].includes(category)) {
        showPage('settings-detail');
        showSettingsCategory(category);
    } else {
        showPage(['todo', 'my', 'archive', 'debug', 'settings'].includes(route) ? route : 'todo');
    }
    if (currentPage === 'archive') {
        await openArchivePage();
    } else if (currentPage === 'debug') {
        await openDebugPage();
    }
}
window.addEventListener('hashchange', activateCurrentPage);

async function openArchivePage() {
    const requestId = pageRequestId;
    completedTaskList.replaceChildren();
    completedTaskCount.textContent = '';
    showMessage('アーカイブを読み込んでいます。');

    try {
        await getCompletedTasks(requestId);
        if (requestId === pageRequestId) {
            showMessage('');
        }
    } catch (error) {
        if (requestId === pageRequestId) {
            showMessage(error.message, true);
        }
    }
}

function formatDebugDate(value) {
    const date = parseDatabaseDate(value);
    return Number.isNaN(date.getTime()) ? '未記録'
        : date.toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'medium' });
}

function appendDebugSection(title, rows) {
    const section = document.createElement('section');
    section.className = 'rounded-2xl border border-line bg-card p-5 shadow-sm';
    const heading = document.createElement('h2');
    heading.className = 'mb-4 text-lg font-bold';
    heading.textContent = title;
    const list = document.createElement('dl');
    list.className = 'space-y-4 text-sm';
    for (const [label, value] of rows) {
        const row = document.createElement('div');
        const term = document.createElement('dt');
        term.className = 'text-xs font-semibold text-ink';
        term.textContent = label;
        const description = document.createElement('dd');
        description.className = 'mt-1 whitespace-pre-wrap break-all';
        description.textContent = String(value);
        row.append(term, description);
        list.append(row);
    }
    section.append(heading, list);
    debugContent.append(section);
}

function renderDebugData(data) {
    debugContent.replaceChildren();
    const { account, password, session, task_summary: summary, runtime } = data;
    const cookie = session.cookie_settings;
    appendDebugSection('アカウント', [
        ['ユーザーID', account.id],
        ['メールアドレス', account.email],
        ['アカウント作成日時', formatDebugDate(account.created_at)],
        ['保存済みパスワードハッシュ', account.password_hash ?? '旧不具合の影響が疑われるため非表示'],
        ['パスワードの保存状態', password.requires_reset ? '旧不具合の影響が疑われます。パスワードの再設定が必要です。' : '入力パスワードのハッシュを保存'],
        ['ハッシュ方式', password.algorithm],
        ['ハッシュのパラメーター', JSON.stringify(password.parameters)],
        ['ログイン時の検証関数', password.verification_function],
        ['ハッシュの再生成', password.requires_rehash ? '必要' : '不要'],
    ]);
    const methods = { password: 'パスワードでログイン', registration: '新規登録' };
    appendDebugSection('ログイン・セッション', [
        ['認証方法', methods[session.authentication_method] ?? '未記録'],
        ['認証日時', formatDebugDate(session.authenticated_at)],
        ['ログイン時のパスワード照合', session.password_verified ? '成功' : 'このセッションに照合記録なし'],
        ['パスワード照合日時', formatDebugDate(session.password_verified_at)],
        ['セッション名', session.name],
        ['Cookieの有効期間', cookie.lifetime === 0 ? 'ブラウザ終了まで' : `${cookie.lifetime}秒`],
        ['Cookie設定', `HttpOnly: ${cookie.httponly} / Secure: ${cookie.secure} / SameSite: ${cookie.samesite}`],
    ]);
    appendDebugSection('タスク', [
        ['全タスク', `${summary.total}件`],
        ['未完了', `${summary.active}件`],
        ['アーカイブ', `${summary.archived}件`],
        ['平均進捗', `${summary.average_progress}%`],
        ['タスクの最終更新', formatDebugDate(summary.last_updated_at)],
    ]);
    appendDebugSection('サーバー・データベース', [
        ['サーバー日時（タイムゾーン付き）', runtime.server_time],
        ['サーバーのタイムゾーン', runtime.server_timezone],
        ['PHPバージョン', runtime.php_version],
        ['DB接続', `${runtime.database_driver} / 接続成功`],
        ['PostgreSQLバージョン', runtime.database_version],
        ['DBのタイムゾーン', runtime.database_timezone],
        ['並び順の保存カラム', runtime.has_sort_order_column ? 'あり' : 'なし'],
        ['完了日時の保存カラム', runtime.has_completed_at_column ? 'あり' : 'なし'],
    ]);
    appendDebugSection('ブラウザ', [
        ['ページ', `${window.location.pathname}${window.location.hash}`],
        ['言語', navigator.language],
        ['タイムゾーン', Intl.DateTimeFormat().resolvedOptions().timeZone],
        ['画面サイズ', `${window.innerWidth} × ${window.innerHeight}`],
        ['接続状態', navigator.onLine ? 'オンライン' : 'オフライン'],
        ['User Agent', navigator.userAgent],
    ]);
    const details = document.createElement('details');
    details.className = 'rounded-2xl border border-line bg-card p-5 shadow-sm';
    const heading = document.createElement('summary');
    heading.className = 'cursor-pointer font-semibold';
    heading.textContent = 'タスクの保存データ（JSON）';
    const json = document.createElement('pre');
    json.className = 'mt-4 whitespace-pre-wrap break-all text-xs';
    json.textContent = JSON.stringify(data.tasks, null, 2);
    details.append(heading, json);
    debugContent.append(details);
}

async function openDebugPage() {
    const requestId = pageRequestId;
    debugContent.replaceChildren();
    refreshDebugButton.disabled = true;
    showMessage('デバッグ情報を読み込んでいます。');
    try {
        const result = await requestJson('/api/debug.php', { cache: 'no-store' });
        if (requestId !== pageRequestId || appShell.classList.contains('hidden')) {
            return;
        }
        renderDebugData(result.data);
        showMessage('');
    } catch (error) {
        if (requestId === pageRequestId) {
            showMessage(error.message, true);
        }
    } finally {
        if (requestId === pageRequestId) {
            refreshDebugButton.disabled = false;
        }
    }
}
refreshDebugButton.addEventListener('click', () => {
    pageRequestId++;
    openDebugPage();
});
archiveSearch.addEventListener('input', renderCompletedTasks);
archiveDate.addEventListener('change', renderCompletedTasks);
clearArchiveFiltersButton.addEventListener('click', () => {
    archiveSearch.value = '';
    archiveDate.value = '';
    renderCompletedTasks();
});

function validatePasswordConfirmation() {
    const matches = registerPassword.value === registerPasswordConfirmation.value;
    registerPasswordConfirmation.setCustomValidity(matches ? '' : '確認用パスワードが一致しません。');
    return matches;
}
registerPassword.addEventListener('input', validatePasswordConfirmation);
registerPasswordConfirmation.addEventListener('input', validatePasswordConfirmation);

loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
        await authenticate('/api/login.php', loginForm);
    } catch (error) {
        showAuthMessage(error.message);
    }
});

registerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!validatePasswordConfirmation()) {
        registerPasswordConfirmation.reportValidity();
        showAuthMessage('確認用パスワードが一致しません。');
        return;
    }

    try {
        await authenticate('/api/register.php', registerForm);
    } catch (error) {
        showAuthMessage(error.message);
    }
});

logoutButton.addEventListener('click', async () => {
    try {
        await settingsSaveQueue?.flush();
        await requestJson('/api/logout.php', { method: 'POST' });
        pageRequestId++;
        taskList.replaceChildren();
        completedTasks = [];
        completedTaskList.replaceChildren();
        debugContent.replaceChildren();
        archiveSearch.value = '';
        archiveDate.value = '';
        loginForm.reset();
        registerForm.reset();
        registerPasswordConfirmation.setCustomValidity('');
        resetUserColors();
        showAuthView();
        showPage('todo');
        window.history.replaceState(null, '', '#todo');
        selectAuthForm('login');
    } catch (error) {
        showMessage(error.message, true);
    }
});

async function initialize() {
    const response = await fetch('/api/me.php', { cache: 'no-store' });

    if (response.status === 401) {
        showAuthView();
        return;
    }

    const result = await response.json();

    if (!response.ok) {
        throw new Error(result.error ?? 'ログイン状態を確認できませんでした。');
    }

    await showApp(result.data);
    await getTasks();
}

initialize().catch((error) => {
    showAuthView();
    showAuthMessage(error.message);
}).finally(() => {
    document.documentElement.classList.remove('initializing');
});
