#!/bin/sh

color="#1BE3C6"

font-awesome-svg-png --nopadding --sizes 192 --color "$color" --icons clock-o --png --dest .
mv "$color/png/192/clock-o.png" img/icon.png
rm -rf "$color"
