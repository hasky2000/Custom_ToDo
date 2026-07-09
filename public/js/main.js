function render_tasks() {
    fetch("../php/get_task.php")
    .then(response => response.json())
    .then(todos => {
        const todo_container = document.getElementById("todo");
        todo_container.innerHTML = "";
        todos.forEach(todo => {
            const id = todo.id;
            console.log(todo.title);
            const card = document.createElement("div");
            card.className = "bg-cyan-50 my-4"
            card.innerHTML = `
                <h2 class="text-2xl">${todo.title}</h2>
                <p>content: ${todo.content} ID: ${todo.id}</p>
                <p>priority: ${todo.priority}</p>
                <button class="del-but" data-id="${id}">DEL</button>
            `;
            todo_container.appendChild(card);
            const button = card.querySelector(".del-but")
            button.addEventListener("click", (event) => {
                const id = event.target.dataset.id;
                fetch("php/del_task.php", {
                    method: "post",
                    body: new URLSearchParams({
                        id: id
                    })
                })
                .then(() => {
                    render_tasks();
                })
            })
        });
    });

}

document.getElementById("submit")
    .addEventListener("click", () => {
        console.log("clicked")
        const title = 
            document.getElementById("title").value;
        const content =
            document.getElementById("content").value;
        const priority = 
            document.getElementById("priority").value;
        fetch("php/add_task.php", {
            method: "post",
            body: new URLSearchParams({
                title: title,
                content: content,
                priority: priority
            })
        }).then(() =>{
            render_tasks();
        });
    });

document.getElementById("get")
    .addEventListener("click", () => {
        render_tasks();
    })
