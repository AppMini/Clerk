#!/bin/sh

if [ "$1" = "" ]
then
  echo "Usage: $0 EVENT-TYPE [COMMENT...]"
else
  event="$1"; shift
  if [ "$1" != "" ]
  then
    comment="&comment=$@"
  fi

  # https://stackoverflow.com/questions/804118/best-timestamp-format-for-csv-excel
  timestamp=`date +"%Y-%m-%d %H:%M:%S"`

  curl 'http://localhost:8000/server/index.php' -H 'Content-Type: application/x-www-form-urlencoded' --data "event=${event}&timestamp=${timestamp}${comment}"
fi
