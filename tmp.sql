    CREATE TABLE Todos (
        todo_id SERIAL PRIMARY KEY,
        todo_title VARCHAR(64) NOT NULL,
        todo_content VARCHAR(256) NOT NULL,
        todo_priority INTEGER NOT NULL DEFAULT 3,
        todo_deadline TIMESTAMP,
        todo_completed BOOLEAN NOT NULL DEFAULT FALSE,
        todo_progress INTEGER NOT NULL DEFAULT 0,
        todo_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );