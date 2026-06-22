document.getElementById("submit")
    .addEventListener("click", () => {
        console.log("clicked")
        const title = 
            document.getElementById("title").value;
        const content =
            document.getElementById("content").value;
        const priority = 
            document.getElementById("priority").value;
        document.getElementById("view_title")
            .textContent = "タイトル: " + title;
        document.getElementById("view_content")
            .textContent = "詳細: " + content;
        document.getElementById("view_priority")
            .textContent = "優先度: " + priority;
    })