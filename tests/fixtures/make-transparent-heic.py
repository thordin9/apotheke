#!/usr/bin/env python3
"""Generate tests/fixtures/transparent.heic (a real HEIC with an alpha plane).

Needs Pillow and pillow-heif (which bundles libheif with an HEVC encoder).
The committed fixture is the file this script wrote: 32x32, the left half
opaque rgb(200,30,40), the right half fully transparent.
"""
from pathlib import Path

import pillow_heif
from PIL import Image

out = Path(__file__).with_name("transparent.heic")
im = Image.new("RGBA", (32, 32), (0, 0, 0, 0))
for x in range(16):
    for y in range(32):
        im.putpixel((x, y), (200, 30, 40, 255))
pillow_heif.from_pillow(im).save(out, quality=90)
print(out, out.stat().st_size)
