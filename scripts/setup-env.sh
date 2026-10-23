#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -e .env ]; then
    echo '既存の .env を使用します。'
    exit 0
fi

umask 077
database_password=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')

# 他の処理が先に作成した場合も、既存のファイルは上書きしない。
set -o noclobber
sed "s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=${database_password}/" .env.example > .env
echo '.env を作成し、DB用パスワードを生成しました。'
