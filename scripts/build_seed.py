"""Build seed.sql for the PHP/MySQL yearbook from a JSON file.

Accepts either the legacy data/alumni.json (entries with picture_link) or an
admin.php backup (entries with id / is_deleted / photo). Stdlib only.

Usage: python scripts/build_seed.py [input_json=data/alumni.json] [output_sql=seed.sql]
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
IMG_DIR = ROOT / "static" / "img"
KNOWN_IMG_EXTS = ("jpg", "jpeg", "png", "JPG", "webp")

LEGACY_COLUMNS = ("email", "fullname", "graduation_year", "education", "work",
                  "photo_filename", "submitted_at", "is_deleted")
BACKUP_COLUMNS = ("id",) + LEGACY_COLUMNS


def resolve_photo_filename(entry: dict, img_dir: Path) -> str | None:
    photo = entry.get("photo")
    if photo:
        name = str(photo).replace("\\", "/").rsplit("/", 1)[-1]
        return None if name == "default.jpg" else name
    link = str(entry.get("picture_link") or "")
    if "?id=" not in link:
        return None
    pic_id = link.split("?id=", 1)[1].split("&", 1)[0].strip()
    if not pic_id:
        return None
    for ext in KNOWN_IMG_EXTS:
        if (img_dir / f"{pic_id}.{ext}").is_file():
            return f"{pic_id}.{ext}"
    return None


def sql_str(value) -> str:
    if value is None:
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


def build_seed_sql(payload: dict, img_dir: Path) -> str:
    entries = payload.get("entries", [])
    is_backup = bool(entries) and all("id" in e for e in entries)
    if is_backup:
        entries = sorted(entries, key=lambda e: int(e["id"]))
        columns = BACKUP_COLUMNS
    else:
        entries = sorted(entries, key=lambda e: str(e.get("timestamp", "")))
        columns = LEGACY_COLUMNS

    col_sql = ", ".join(f"`{c}`" for c in columns)
    lines = ["SET NAMES utf8mb4;", "SET time_zone = '+07:00';"]
    for e in entries:
        values = []
        if is_backup:
            values.append(str(int(e["id"])))
        values += [
            sql_str(str(e.get("email", "")).strip().lower()),
            sql_str(e.get("fullname", "")),
            str(int(e["year"])),
            sql_str(e.get("education", "")),
            sql_str(e.get("work") or None),
            sql_str(resolve_photo_filename(e, img_dir)),
            sql_str(e.get("timestamp", "")),
            str(1 if int(e.get("is_deleted", 0) or 0) else 0),
        ]
        lines.append(f"INSERT INTO alumni_entries ({col_sql}) VALUES ({', '.join(values)});")
    return "\n".join(lines) + "\n"


def main() -> None:
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "data" / "alumni.json"
    dst = Path(sys.argv[2]) if len(sys.argv) > 2 else ROOT / "seed.sql"
    payload = json.loads(src.read_text(encoding="utf-8"))
    dst.write_text(build_seed_sql(payload, IMG_DIR), encoding="utf-8", newline="\n")
    print(f"Wrote {len(payload.get('entries', []))} entries to {dst}")


if __name__ == "__main__":
    main()
