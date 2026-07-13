<?php
    session_start();
    if (!isset($_SESSION["user"]))
    echo "<p>[DEBUG] username: ".$_SESSION["user"]["username"]."</p>";
?>

<html>
        <?php $page_title = "HOME"; require("components/head.php"); ?>
    <body>
        <a href="login.php">login</a>

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
            <button type="button" id="get">get</button>
        </section>
        <section>
            <div id="todo"></div>
        </section>
    </body>
</html>
<script src="js/main.js"></script>