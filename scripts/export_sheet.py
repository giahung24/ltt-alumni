"""Snapshot the Google Sheet to data/alumni.json and sync missing Drive photos.

Writes RAW education/work text (no HTML). The JS frontend
(assets/js/text.js) wraps year ranges in <span class="year_range"> and
inserts <br> between lines at render time. Missing alumni portraits in
static/img/ are downloaded via Google Drive API v3 using the same service
account so Restricted Google Form uploads display without client auth.

Requires .streamlit/secrets.toml with two TOML tables:
  [gg_service_account]   # Google service-account JSON, key by key
  [gg_data_sheet]
    id = "<spreadsheet_id>"

Output: data/alumni.json with shape {"exported_at": "YYYY-MM-DD",
"entries": [ {timestamp, fullname, year, picture_link, education,
work, email, facebook}, ... ]}, plus static/img/<picture_id>.<ext>.

Usage: python scripts/export_sheet.py  (requires Python >= 3.11)
"""

from __future__ import annotations

import base64
import json
import os
import re
import sys
import time
import tomllib
from datetime import date
from pathlib import Path
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
IMG_DIR = ROOT / "static" / "img"
KNOWN_IMG_EXTS = ("jpg", "jpeg", "png", "JPG", "webp")
MIME_TO_EXT = {
    "image/jpeg": "jpg",
    "image/jpg": "jpg",
    "image/png": "png",
    "image/webp": "webp",
}

DATA_COLUMNS_NAME = [
    "timestamp", "fullname", "year", "picture_link",
    "education", "work", "email", "facebook",
]

SCOPES = [
    "https://www.googleapis.com/auth/drive",
    "https://www.googleapis.com/auth/spreadsheets.readonly",
]


def resolve_secrets_path() -> Path:
    env_path = os.environ.get("SECRETS_TOML")
    if env_path:
        return Path(env_path)
    local_path = ROOT / ".streamlit" / "secrets.toml"
    if local_path.is_file():
        return local_path
    git_file = ROOT / ".git"
    if git_file.is_file():
        text = git_file.read_text(encoding="utf-8").strip()
        match = re.match(r"^gitdir:\s*(.+)/\.git/worktrees/[^/]+$", text)
        if match:
            main_secrets = Path(match.group(1)) / ".streamlit" / "secrets.toml"
            if main_secrets.is_file():
                return main_secrets
    return local_path


def load_secrets() -> dict:
    with resolve_secrets_path().open("rb") as f:
        return tomllib.load(f)


def build_credentials(secrets: dict):
    from oauth2client.service_account import ServiceAccountCredentials

    return ServiceAccountCredentials.from_json_keyfile_dict(
        secrets["gg_service_account"], scopes=SCOPES,
    )


def get_drive_access_token(secrets: dict, creds=None) -> str:
    if creds is not None:
        return creds.get_access_token().access_token

    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding

    sa = secrets["gg_service_account"]
    now = int(time.time())
    b64 = lambda b: base64.urlsafe_b64encode(b).rstrip(b"=").decode("ascii")
    header = b64(json.dumps({"alg": "RS256", "typ": "JWT"}).encode("utf-8"))
    claims = b64(json.dumps({
        "iss": sa["client_email"],
        "scope": " ".join(SCOPES),
        "aud": sa.get("token_uri", "https://oauth2.googleapis.com/token"),
        "iat": now,
        "exp": now + 3600,
    }).encode("utf-8"))
    signing_input = f"{header}.{claims}".encode("ascii")
    private_key = serialization.load_pem_private_key(
        sa["private_key"].encode("utf-8"), password=None,
    )
    sig = b64(private_key.sign(signing_input, padding.PKCS1v15(), hashes.SHA256()))
    body = urlencode({
        "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion": f"{header}.{claims}.{sig}",
    }).encode("ascii")
    req = Request(
        sa.get("token_uri", "https://oauth2.googleapis.com/token"),
        data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    with urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))["access_token"]


def read_raw_sheet(secrets: dict, creds):
    from gspread_pandas import Spread
    from pandas import to_datetime

    sheet_id = secrets["gg_data_sheet"]["id"]
    df = Spread(sheet_id, creds=creds).sheet_to_df(index=0)
    df.columns = DATA_COLUMNS_NAME
    df["timestamp"] = to_datetime(df["timestamp"])
    df["fullname"] = df["fullname"].apply(lambda x: x.title())
    return df.fillna("").astype(str)


def keep_last_entry_by_email(df):
    return df.sort_values("timestamp").drop_duplicates(subset=["email"], keep="last")


def extract_picture_id(link: str) -> str:
    if not link:
        return ""
    if "?id=" in link:
        return link.split("?id=", 1)[1].split("&", 1)[0].strip()
    match = re.search(r"/d/([a-zA-Z0-9_-]+)", link)
    return match.group(1) if match else ""


def has_local_photo(picture_id: str) -> bool:
    return any((IMG_DIR / f"{picture_id}.{ext}").is_file() for ext in KNOWN_IMG_EXTS)


def drive_get_bytes(url: str, token: str | None = None) -> bytes:
    headers = {"Authorization": f"Bearer {token}"} if token else {}
    req = Request(url, headers=headers)
    with urlopen(req, timeout=30) as resp:
        return resp.read()


def sniff_image_ext(data: bytes, fallback: str = "jpg") -> str:
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "png"
    if data.startswith(b"\xff\xd8\xff"):
        return "jpg"
    if len(data) >= 12 and data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    return fallback


def sync_missing_photos(entries: list[dict], secrets: dict, creds=None) -> int:
    IMG_DIR.mkdir(parents=True, exist_ok=True)
    missing_ids = []
    seen = set()
    for entry in entries:
        pic_id = extract_picture_id(entry.get("picture_link", ""))
        if pic_id and pic_id not in seen and not has_local_photo(pic_id):
            seen.add(pic_id)
            missing_ids.append(pic_id)

    if not missing_ids:
        return 0

    token = get_drive_access_token(secrets, creds)
    downloaded = 0
    for pic_id in missing_ids:
        try:
            enc_id = quote(pic_id, safe="")
            meta_url = (
                f"https://www.googleapis.com/drive/v3/files/{enc_id}"
                "?fields=mimeType,name,thumbnailLink&supportsAllDrives=true"
            )
            meta = json.loads(drive_get_bytes(meta_url, token).decode("utf-8"))
            mime = str(meta.get("mimeType", "")).lower()

            # Prefer Drive's 800px server-side thumbnail (EXIF-rotated, ~50KB, strips GPS/EXIF);
            # fall back to raw media if Drive hasn't generated a thumbnail yet.
            data = b""
            if meta.get("thumbnailLink"):
                thumb_url = re.sub(r"=s\d+$", "=s800", str(meta["thumbnailLink"]))
                try:
                    data = drive_get_bytes(thumb_url)
                except Exception:  # noqa: BLE001
                    data = b""

            if not data:
                if mime not in MIME_TO_EXT:
                    print(f"Skipping photo {pic_id}: unsupported mimeType '{mime}'")
                    continue
                media_url = (
                    f"https://www.googleapis.com/drive/v3/files/{enc_id}"
                    "?alt=media&supportsAllDrives=true"
                )
                data = drive_get_bytes(media_url, token)

            if not data:
                continue
            ext = sniff_image_ext(data, MIME_TO_EXT.get(mime, "jpg"))
            dest = IMG_DIR / f"{pic_id}.{ext}"
            dest.write_bytes(data)
            downloaded += 1
            print(f"Downloaded photo {dest.relative_to(ROOT)} ({len(data)} bytes)")
        except Exception as exc:  # noqa: BLE001
            print(f"Warning: could not download photo {pic_id}: {exc}")

    return downloaded


def main() -> None:
    secrets = load_secrets()
    out_path = ROOT / "data" / "alumni.json"
    creds = None

    if "--photos-only" in sys.argv and out_path.is_file():
        payload = json.loads(out_path.read_text(encoding="utf-8"))
        entries = payload.get("entries", [])
    else:
        creds = build_credentials(secrets)
        df = keep_last_entry_by_email(read_raw_sheet(secrets, creds))
        entries = []
        for _, row in df.iterrows():
            try:
                year = int(row["year"])
            except (TypeError, ValueError):
                continue
            entries.append({
                "timestamp": str(row.get("timestamp", "")),
                "fullname": str(row.get("fullname", "")),
                "year": year,
                "picture_link": str(row.get("picture_link", "")),
                "education": str(row.get("education", "")),
                "work": str(row.get("work", "")),
                "email": str(row.get("email", "")),
                "facebook": str(row.get("facebook", "")),
            })

        out_path.parent.mkdir(parents=True, exist_ok=True)
        payload = {"exported_at": date.today().isoformat(), "entries": entries}
        out_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"Wrote {len(entries)} entries to {out_path}")

    synced = sync_missing_photos(entries, secrets, creds)
    print(f"Synced {synced} new photo(s) to {IMG_DIR}")


if __name__ == "__main__":
    main()
