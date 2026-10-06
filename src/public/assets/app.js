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

function showAuthMessage(text) {
    authMessage.textContent = text;
}

function showAuthView() {
    appShell.classList.add('hidden');
    authView.classList.remove('hidden');
}

function showApp(user) {
    currentUserEmail.textContent = user.email;
    settingsEmail.textContent = user.email;
    authView.classList.add('hidden');
    appShell.classList.remove('hidden');
}

function showPage(page) {
    const isTodoPage = page === 'todo';
    todoPage.classList.toggle('hidden', !isTodoPage);
    myPage.classList.toggle('hidden', isTodoPage);
    openTaskFormButton.classList.toggle('hidden', !isTodoPage);
    pageTitle.textContent = isTodoPage ? '今日のタスク' : 'マイページ';
    showTodoPageButton.className = isTodoPage
        ? 'flex items-center justify-center gap-2 rounded-xl bg-orange-50 px-3 py-3 text-sm font-semibold text-accent'
        : 'flex items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-medium text-muted';
    showMyPageButton.className = isTodoPage
        ? 'flex items-center justify-center gap-2 rounded-xl px-3 py-3 text-sm font-medium text-muted'
        : 'flex items-center justify-center gap-2 rounded-xl bg-orange-50 px-3 py-3 text-sm font-semibold text-accent';
    showMessage('');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function selectAuthForm(type) {
    const isLogin = type === 'login';
    loginForm.classList.toggle('hidden', !isLogin);
    registerForm.classList.toggle('hidden', isLogin);
    showLoginButton.className = isLogin
        ? 'rounded-lg bg-white px-3 py-2 text-sm font-semibold shadow-sm'
        : 'rounded-lg px-3 py-2 text-sm font-semibold text-muted';
    showRegisterButton.className = isLogin
        ? 'rounded-lg px-3 py-2 text-sm font-semibold text-muted'
        : 'rounded-lg bg-white px-3 py-2 text-sm font-semibold shadow-sm';
    showAuthMessage('');
}

function showMessage(text, isError = false) {
    message.textContent = text;
    message.className = `mb-4 min-h-6 text-sm ${isError ? 'text-red-700' : 'text-green-700'}`;
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
        }),
    });

    form.reset();
    showApp(result.data);
    await getTasks();
}

function parseDatabaseDate(value) {
    return new Date(value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
}

function toDateTimeLocalValue(value) {
    if (!value) {
        return '';
    }

    const date = parseDatabaseDate(value);
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
        + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function createTaskElement(task) {
    const item = document.createElement('li');
    const details = document.createElement('details');
    details.className = 'group overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm';

    const summary = document.createElement('summary');
    summary.className = 'flex cursor-pointer list-none items-center gap-4 px-5 py-5';

    const completeCheckbox = document.createElement('input');
    completeCheckbox.type = 'checkbox';
    completeCheckbox.setAttribute('aria-label', `${task.title}を完了する`);
    completeCheckbox.className = 'size-6 shrink-0 cursor-pointer rounded border-stone-300 accent-orange-600';
    completeCheckbox.addEventListener('click', (event) => {
        event.stopPropagation();
    });
    completeCheckbox.addEventListener('change', () => completeTask(task.id));

    const title = document.createElement('h3');
    title.className = 'min-w-0 flex-1 truncate text-lg font-semibold';
    title.textContent = task.title;

    const chevron = document.createElement('span');
    chevron.className = 'grid size-9 shrink-0 place-items-center rounded-full bg-stone-100 text-muted transition-all group-open:rotate-180 group-open:bg-orange-50 group-open:text-accent';
    chevron.setAttribute('aria-hidden', 'true');
    chevron.innerHTML = '<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    summary.append(completeCheckbox, title, chevron);

    const detailArea = document.createElement('div');
    detailArea.className = 'border-t border-stone-100 px-5 pb-5 pt-4';

    if (task.content) {
        const content = document.createElement('p');
        content.className = 'whitespace-pre-wrap text-sm leading-6 text-muted';
        content.textContent = task.content;
        detailArea.append(content);
    }

    if (task.scheduled_at) {
        const scheduledAt = document.createElement('p');
        scheduledAt.className = 'mt-3 text-sm font-semibold text-accent';
        const date = parseDatabaseDate(task.scheduled_at);
        scheduledAt.textContent = `予定: ${date.toLocaleString('ja-JP', {
            dateStyle: 'medium',
            timeStyle: 'short',
        })}`;
        detailArea.append(scheduledAt);
    }

    const progressArea = document.createElement('div');
    progressArea.className = 'mt-5 rounded-xl bg-stone-50 p-4';

    const progressHeader = document.createElement('div');
    progressHeader.className = 'mb-3 flex items-center justify-between';

    const progressLabel = document.createElement('label');
    progressLabel.className = 'text-sm font-semibold';
    progressLabel.textContent = '進捗';
    progressLabel.htmlFor = `progress-${task.id}`;

    const progressValue = document.createElement('output');
    progressValue.className = 'text-sm font-bold text-accent';
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
    updateButton.className = 'mt-4 w-full rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white';
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
    editButton.className = 'rounded-lg bg-stone-100 px-4 py-2 text-sm font-semibold text-ink hover:bg-stone-200';
    editButton.textContent = '編集';
    editButton.addEventListener('click', () => openEditDialog(task));

    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'rounded-lg px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50';
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
    taskList.replaceChildren();
    taskCount.textContent = `${result.data.length}件`;

    if (result.data.length === 0) {
        const emptyItem = document.createElement('li');
        emptyItem.className = 'rounded-2xl border border-dashed border-stone-300 bg-white/50 p-8 text-center text-muted';
        emptyItem.textContent = 'TODOはまだありません。';
        taskList.append(emptyItem);
        return;
    }

    taskList.append(...result.data.map(createTaskElement));
}

function createCompletedTaskElement(task) {
    const item = document.createElement('li');
    item.className = 'rounded-2xl border border-stone-200 bg-white p-5 shadow-sm';

    const heading = document.createElement('div');
    heading.className = 'flex items-start gap-3';

    const checkIcon = document.createElement('span');
    checkIcon.className = 'grid size-7 shrink-0 place-items-center rounded-full bg-green-100 text-green-700';
    checkIcon.innerHTML = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    const textArea = document.createElement('div');
    textArea.className = 'min-w-0';
    const title = document.createElement('h3');
    title.className = 'font-semibold text-stone-500 line-through';
    title.textContent = task.title;
    textArea.append(title);

    if (task.content) {
        const content = document.createElement('p');
        content.className = 'mt-1 text-sm text-muted';
        content.textContent = task.content;
        textArea.append(content);
    }

    const completedAt = document.createElement('p');
    completedAt.className = 'mt-3 text-xs font-medium text-muted';
    completedAt.textContent = `完了: ${parseDatabaseDate(task.updated_at).toLocaleString('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
    })}`;
    textArea.append(completedAt);
    heading.append(checkIcon, textArea);
    item.append(heading);

    return item;
}

async function getCompletedTasks() {
    const result = await requestJson('/api/get_completed_tasks.php');
    completedTaskList.replaceChildren();
    completedTaskCount.textContent = `${result.data.length}件`;

    if (result.data.length === 0) {
        const emptyItem = document.createElement('li');
        emptyItem.className = 'rounded-2xl border border-dashed border-stone-300 bg-white/50 p-8 text-center text-muted';
        emptyItem.textContent = '完了したTODOはまだありません。';
        completedTaskList.append(emptyItem);
        return;
    }

    completedTaskList.append(...result.data.map(createCompletedTaskElement));
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
showTodoPageButton.addEventListener('click', () => showPage('todo'));
showMyPageButton.addEventListener('click', async () => {
    showPage('my');

    try {
        await getCompletedTasks();
    } catch (error) {
        showMessage(error.message, true);
    }
});

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

    try {
        await authenticate('/api/register.php', registerForm);
    } catch (error) {
        showAuthMessage(error.message);
    }
});

logoutButton.addEventListener('click', async () => {
    try {
        await requestJson('/api/logout.php', { method: 'POST' });
        taskList.replaceChildren();
        showAuthView();
        showPage('todo');
        selectAuthForm('login');
    } catch (error) {
        showMessage(error.message, true);
    }
});

async function initialize() {
    const response = await fetch('/api/me.php');

    if (response.status === 401) {
        showAuthView();
        return;
    }

    const result = await response.json();

    if (!response.ok) {
        throw new Error(result.error ?? 'ログイン状態を確認できませんでした。');
    }

    showApp(result.data);
    showPage('todo');
    await getTasks();
}

initialize().catch((error) => {
    showAuthView();
    showAuthMessage(error.message);
});
