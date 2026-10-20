# Custom ToDo

## 引き継ぎ・Windowsでの開発

[引き継ぎ資料](docs/HANDOVER.md)に、環境一覧、Windows（WSL2＋Docker Desktop）での構築手順、テスト、トラブル対応をまとめています。
共同開発者のAIには、この資料の「最初にAIへ渡す依頼文」とリポジトリを渡してください。

## 環境構築

Docker、Docker Composeが使える環境で、以下を実行します。

1. 環境設定のひな形をコピーします。

   ```sh
   cp .env.example .env
   ```

2. `.env` の `POSTGRES_PASSWORD` を任意のパスワードに変更します。
3. CSSのビルドに必要な依存パッケージをインストールします。

   ```sh
   docker compose run --rm --no-deps node npm ci
   ```

4. アプリを起動します。

   ```sh
   docker compose up -d --build
   ```

ブラウザーで http://localhost:8080 を開きます。

## Gitで管理するファイル

`.env`、`node_modules/`、エディターの個人設定、ログはコミット対象から除外しています。
環境変数を追加した場合は、秘密情報を含めずに `.env.example` も更新してください。

`package-lock.json` とデータベースの初期化SQLは、環境を再現するために管理します。
`src/public/assets/app.css` も、初期表示に必要なCSSとして管理します。
