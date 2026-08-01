#!/usr/bin/env python3
"""
Regenerate pages/assets/social-card.png.

    python3 dev/make-social-card.py

1280x640, which is what GitHub wants for a repository social preview and is also
fine as an og:image. One file serves both, so upload this same PNG under
Settings -> General -> Social preview.

It draws a real inbox entry rather than a logo on a gradient: device headers with
the ingest key redacted, and a binary payload as a hex dump. That is what triops
actually shows you, and a card that promises something the product does not do is
worse than no card.

Needs Pillow (`pip install pillow`) and the system fonts that ship with macOS.
Dev-only, like everything else in this directory — nothing in app/ depends on it.

Every string is placed through put(), which records an overflow if the text runs
past the column it belongs in. The script prints those and exits non-zero rather
than silently writing a card with text collisions, which is how the first three
attempts at this went wrong.
"""

import sys
from PIL import Image, ImageDraw, ImageFont

W, H = 1280, 640

BG     = (247, 249, 249)   # the app's page background
INK    = (26, 32, 33)
MUTED  = (95, 108, 110)
FAINT  = (140, 152, 154)
TEAL   = (0, 102, 102)     # theme.primary in config.sample.php
ORANGE = (204, 102, 51)    # theme.secondary
LINE   = (219, 226, 227)

S  = "/System/Library/Fonts/Supplemental/Arial.ttf"
SB = "/System/Library/Fonts/Supplemental/Arial Bold.ttf"
M  = "/System/Library/Fonts/Menlo.ttc"

card = Image.new("RGB", (W, H), BG)
d = ImageDraw.Draw(card)
problems = []


def put(xy, text, font, fill, limit=None, tag=""):
    d.text(xy, text, font=font, fill=fill)
    if limit and xy[0] + d.textlength(text, font=font) > limit:
        problems.append(f"{tag or text[:20]!r} overruns {limit}")


head = ImageFont.truetype(SB, 46)
sub  = ImageFont.truetype(S, 30)
tiny = ImageFont.truetype(S, 19)
lbl  = ImageFont.truetype(SB, 19)
mono = ImageFont.truetype(M, 19)
mons = ImageFont.truetype(M, 18)

# Brand rule: the two seed colours bitwrench derives the whole palette from.
d.rectangle([0, 0, W, 9], fill=TEAL)
d.rectangle([0, 0, 200, 9], fill=ORANGE)

# ---------------------------------------------------------------- left column
LX, LRIGHT = 84, 620

logo = Image.open("app/assets/triops-logo.png").convert("RGBA")
lw = 370
logo = logo.resize((lw, int(logo.height * lw / logo.width)), Image.LANCZOS)
card.paste(logo, (LX, 100), logo)

put((LX, 254), "See what your hardware",   head, INK,   LRIGHT, "headline1")
put((LX, 310), "is actually sending.",     head, INK,   LRIGHT, "headline2")
put((LX, 394), "Raw bytes, headers and",   sub,  MUTED, LRIGHT, "sub1")
put((LX, 432), "timing — from an ESP32,",  sub,  MUTED, LRIGHT, "sub2")
put((LX, 470), "a Pi, anything with HTTP.", sub, MUTED, LRIGHT, "sub3")
put((LX, 536), "Unzip into a web directory and it runs.", tiny, FAINT, LRIGHT, "foot1")
put((LX, 564), "PHP 8+.  No build step, no services.",   tiny, FAINT, LRIGHT, "foot2")

# ---------------------------------------------------------------- right panel
PX0, PX1, PAD = 672, W - 84, 26
PY0, PY1 = 100, 548
d.rounded_rectangle([PX0, PY0, PX1, PY1], radius=12, fill=(255, 255, 255), outline=LINE, width=2)

IN_L, IN_R = PX0 + PAD, PX1 - PAD
put((IN_L, PY0 + 22), "22:41:07.428  ·  POST  ·  20 bytes", tiny, FAINT, IN_R, "meta")
put((IN_L, PY0 + 62), "6 headers", lbl, MUTED, IN_R, "hdrlabel")

rows = [
    ("User-Agent:",   "ESP32HTTPClient"),
    ("Content-Type:", "application/cbor"),
    ("X-Device-Id:",  "esp32-01"),
    ("X-Triops-Key:", "[redacted]"),
]
vx = IN_L + max(d.textlength(k, font=mono) for k, _ in rows) + 14
for i, (k, v) in enumerate(rows):
    y = PY0 + 96 + i * 29
    put((IN_L, y), k, mono, INK, IN_R, f"hdrkey{i}")
    put((vx, y), v, mono, ORANGE if v == "[redacted]" else MUTED, IN_R, f"hdrval{i}")

put((IN_L, PY0 + 228), "binary — 20 bytes, not valid UTF-8", tiny, FAINT, IN_R, "binlabel")

HB0, HB1 = PY0 + 258, PY1 - 24
d.rounded_rectangle([IN_L, HB0, IN_R, HB1], radius=8, fill=(250, 251, 251), outline=LINE, width=1)

# Six bytes a row, not eight: eight puts the ASCII gutter through the hex.
dump = [
    ("0000", "aa 55 01 10 65 73", "|.U..es|"),
    ("0006", "70 33 32 2d 30 31", "|p32-01|"),
    ("000c", "00 0e 01 60 ff d2", "|...`..|"),
    ("0012", "3c 91",             "|<.|"),
]
hex_x = IN_L + 16 + d.textlength("0000  ", font=mons)
asc_x = hex_x + d.textlength("aa 55 01 10 65 73  ", font=mons)
for i, (off, hexs, asc) in enumerate(dump):
    y = HB0 + 18 + i * 28
    put((IN_L + 16, y), off,  mons, FAINT, IN_R, f"off{i}")
    put((hex_x, y),     hexs, mons, INK,   IN_R, f"hex{i}")
    put((asc_x, y),     asc,  mons, FAINT, IN_R, f"asc{i}")

if problems:
    for p in problems:
        print("overflow:", p, file=sys.stderr)
    sys.exit(1)

out = "pages/assets/social-card.png"
card.save(out, optimize=True)
print(f"wrote {out} ({W}x{H})")
