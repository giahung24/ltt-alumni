"""Snapshot the Google Sheet to data/alumni.json for the HTML/JS frontend.

Run locally (not in the deployed bundle). Requires the same .streamlit/secrets.toml
used by the Streamlit app. Output: data/alumni.json with shape
{"exported_at": "YYYY-MM-DD", "entries": [ {timestamp, fullname, year, picture_link,
education, work, email, facebook}, ... ]}.

Usage: python scripts/export_sheet.py
"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from utils import keep_last_entry_by_email, read_data_from_spreadsheet  # noqa: E402


def main() -> None:
    df = keep_last_entry_by_email(read_data_from_spreadsheet())
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
