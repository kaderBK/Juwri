#!/bin/bash
# usage: run.sh <driver> <mode: stock|merging>
cd "$(dirname "$0")/../.."
D=$1; M=$2; PORT=$((18700 + RANDOM % 200))
export APP_ENV=local APP_DEBUG=true SESSION_DRIVER=$D CACHE_STORE=file DB_CONNECTION=sqlite DB_DATABASE=$PWD/storage/race/db.sqlite APP_KEY=base64:$(head -c32 /dev/urandom | base64) PHP_CLI_SERVER_WORKERS=4 RACE_MODE=$M
rm -f storage/race/db.sqlite; touch storage/race/db.sqlite
php8.4 -r 'new PDO("sqlite:".getenv("DB_DATABASE")); $p=new PDO("sqlite:".getenv("DB_DATABASE")); $p->exec("create table sessions (id varchar primary key, user_id integer null, ip_address varchar null, user_agent text null, payload text not null, last_activity integer not null)");'
php8.4 -S 127.0.0.1:$PORT storage/race/router.php > storage/race/server-$D-$M.log 2>&1 & S=$!
sleep 2
J=$(mktemp); B=http://127.0.0.1:$PORT
curl -s -c $J -b $J $B/__race/set/en >/dev/null
curl -s -c $J.slow -b $J $B/__race/slow >/dev/null & C=$!
sleep 0.3
curl -s -c $J -b $J $B/__race/set/ar >/dev/null
wait $C
cp $J.slow $J 2>/dev/null
echo "driver=$D mode=$M after: $(curl -s -b $J $B/__race/read)"
kill $S
