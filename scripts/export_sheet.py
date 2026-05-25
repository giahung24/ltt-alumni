"""Snapshot the Google Sheet to data/alumni.json for the HTML/JS frontend.

Writes RAW education/work text (no HTML). The JS frontend
(assets/js/text.js) wraps year ranges in <span class="year_range"> and
inserts <br> between lines at render time. Doing the formatting in JS
keeps a single source of truth and avoids double-escaping when the
data is consumed by the static page.

Run locally (not in the deployed bundle). Requires the same
.streamlit/secrets.toml used by the Streamlit app. Output:
data/alumni.json with shape {"exported_at": "YYYY-MM-DD",
"entries": [ {timestamp, fullname, year, picture_link, education,
work, email, facebook}, ... ]}.

Usage: python scripts/export_sheet.py
"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from gspread_pandas import Spread  # noqa: E402
from oauth2client.service_account import ServiceAccountCredentials  # noqa: E402
from pandas import to_datetime  # noqa: E402
from streamlit import secrets as st_secrets  # noqa: E402

from config import DATA_COLUMNS_NAME  # noqa: E402

SCOPES = [
    "https://www.googleapis.com/auth/drive",
    "https://www.googleapis.com/auth/spreadsheets.readonly",
]


def read_raw_sheet():
    creds = ServiceAccountCredentials.from_json_keyfile_dict(
        st_secrets["gg_service_account"], scopes=SCOPES,
    )
    sheet_id = st_secrets["gg_data_sheet"]["id"]
    df = Spread(sheet_id, creds=creds).sheet_to_df(index=0)
    df.columns = DATA_COLUMNS_NAME
    df["timestamp"] = to_datetime(df["timestamp"])
    df["fullname"] = df["fullname"].apply(lambda x: x.title())
    return df.fillna("").astype(str)


def keep_last_entry_by_email(df):
    return df.sort_values("timestamp").drop_duplicates(subset=["email"], keep="last")


def main() -> None:
    df = keep_last_entry_by_email(read_raw_sheet())
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
