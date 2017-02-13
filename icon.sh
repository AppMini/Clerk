#!/bin/sh

font-awesome-svg-png --nopadding --sizes 192 --color "#0C9" --icons list-alt --png --dest .
mv \#0C9/png/192/list-alt.png img/icon.png
rm -rf \#0C9
