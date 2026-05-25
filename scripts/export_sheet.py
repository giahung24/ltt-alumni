"""Snapshot the Google Sheet to data/alumni.json for the HTML/JS frontend.

Writes RAW education/work text (no HTML). The JS frontend
(assets/js/text.js) wraps year ranges in <span class="year_range"> and
inserts <br> between lines at render time.

Requires .streamlit/secrets.toml with two TOML tables:
  [gg_service_account]   # Google service-account JSON, key by key
  [gg_data_sheet]
    id = "<spreadsheet_id>"

Output: data/alumni.json with shape {"exported_at": "YYYY-MM-DD",
"entries": [ {timestamp, fullname, year, picture_link, education,
work, email, facebook}, ... ]}.

Usage: python scripts/export_sheet.py  (requires Python >= 3.11)
"""

from __future__ import annotations

import json
import tomllib
from datetime import date
from pathlib import Path

from gspread_pandas import Spread
from oauth2client.service_account import ServiceAccountCredentials
from pandas import to_datetime

ROOT = Path(__file__).resolve().parent.parent
SECRETS_PATH = ROOT / ".streamlit" / "secrets.toml"

DATA_COLUMNS_NAME = [
    "timestamp", "fullname", "year", "picture_link",
    "education", "work", "email", "facebook",
]

SCOPES = [
    "https://www.googleapis.com/auth/drive",
    "https://www.googleapis.com/auth/spreadsheets.readonly",
]


def load_secrets() -> dict:
    with SECRETS_PATH.open("rb") as f:
        return tomllib.load(f)


def read_raw_sheet(secrets: dict):
    creds = ServiceAccountCredentials.from_json_keyfile_dict(
        secrets["gg_service_account"], scopes=SCOPES,
    )
    sheet_id = secrets["gg_data_sheet"]["id"]
    df = Spread(sheet_id, creds=creds).sheet_to_df(index=0)
    df.columns = DATA_COLUMNS_NAME
    df["timestamp"] = to_datetime(df["timestamp"])
    df["fullname"] = df["fullname"].apply(lambda x: x.title())
    return df.fillna("").astype(str)


def keep_last_entry_by_email(df):
    return df.sort_values("timestamp").drop_duplicates(subset=["email"], keep="last")


def main() -> None:
    secrets = load_secrets()
    df = keep_last_entry_by_email(read_raw_sheet(secrets))
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

    out_path = ROOT / "data" / "alumni.json"
    out_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {"exported_at": date.today().isoformat(), "entries": entries}
    out_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Wrote {len(entries)} entries to {out_path}")


if __name__ == "__main__":
    main()
