#!/bin/bash
cd "$(dirname "$0")"
for d in file database; do for m in stock merging; do for i in 1 2 3; do bash run.sh $d $m; done; done; done
tail -3 server-file-stock.log
