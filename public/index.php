<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="./output.css" rel="stylesheet">
    </head>
    <body>
        <section>
            <form>
                <lavel for="title">タスク</lavel>
                <br>
                <input
                    type="text"
                    id="title"
                    name="title"
                    maxlength="64"
                    required
                >
                <br>
                <lavel for="content">詳細</lavel>
                <br>
                <textarea
                    id="content"
                    name="content"
                    maxlength="256"
                    rows="5"
                    required
                ></textarea>
                <br>
                <lavel for="priority">優先度</lavel>
                <br>
                <input
                    id="priority"
                    type="number"
                    name="priority"
                    maxlength="1"
                    required
                >
                <br>
                <button type="button" id="submit">登録</button>
            </form>
        </section>
        <section>
            <p id="view_title"></p>
            <p id="view_content"></p>
            <p id="view_priority"></p>
        </section>
    </body>
</html>
<script src="js/main.js"></script>