#!/usr/bin/env python3
"""Generate tests/fixtures/tiny-gps.heic (a real HEIC, not a stub).

Needs Pillow, piexif and heif-enc with an HEVC encoder (libheif-plugin-x265).
The committed fixture is the file this script wrote: 96x64, brand heic, Exif
description "GPSLatitude SerialNumber" and a GPS IFD.
"""
import subprocess
import sys
from pathlib import Path

from PIL import Image
import piexif

out = Path(__file__).with_name("tiny-gps.heic")
jpg = out.with_suffix(".jpg")
im = Image.new("RGB", (96, 64), (180, 30, 40))
for x in range(8):
    for y in range(8):
        im.putpixel((x, y), (10, 200, 30))
exif = {
    "0th": {
        piexif.ImageIFD.ImageDescription: b"GPSLatitude SerialNumber",
        piexif.ImageIFD.Make: b"ApothekeTest",
        piexif.ImageIFD.Orientation: 1,
    },
    "GPS": {
        piexif.GPSIFD.GPSLatitudeRef: b"N",
        piexif.GPSIFD.GPSLatitude: ((37, 1), (48, 1), (0, 1)),
        piexif.GPSIFD.GPSLongitudeRef: b"W",
        piexif.GPSIFD.GPSLongitude: ((122, 1), (24, 1), (0, 1)),
    },
}
im.save(jpg, format="JPEG", quality=90, exif=piexif.dump(exif))
subprocess.run(["heif-enc", "-q", "70", str(jpg), "-o", str(out)], check=True)
jpg.unlink()
data = out.read_bytes()
if b"ftypheic" not in data[:32] or b"GPSLatitude" not in data:
    sys.exit("encoder did not write a HEIC with the Exif description")
print(f"wrote {out} ({len(data)} bytes)")
