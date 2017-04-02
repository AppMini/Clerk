#!/bin/sh

font-awesome-svg-png --nopadding --sizes 192 --color "#0C9" --icons clock-o --png --dest .
mv \#0C9/png/192/clock-o.png img/icon.png
rm -rf \#0C9
