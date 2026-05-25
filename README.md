# Kỷ yếu lớp Pháp ngữ

A small yearbook site for the French-language class (Pháp ngữ) at THPT
Chuyên Lý Tự Trọng, Cần Thơ. Alumni submit their info through a Google
Form; this site renders the resulting Google Sheet as a browsable
directory with photos, education and career history, grouped by
graduation year.

## What's in this branch

A pure HTML / CSS / JavaScript frontend that reads `data/alumni.json`
and renders everything client-side. A scheduled GitHub Action
(`.github/workflows/refresh-data.yml`) runs `scripts/export_sheet.py`
every Sunday at 01:00 GMT+7 to pull the latest sheet and commit a
fresh `data/alumni.json`.

## Previous version

The original Streamlit app lives on the [`streamlit`](../../tree/streamlit)
branch and is currently hosted at <https://ltt-alumni.streamlit.app/>.
It's kept as a backup; new work happens on `main`.

## Hosting

This static site is **not deployed yet**. To publish it via GitHub
Pages: Settings → Pages → Source: `main` / root.

⚠️ GitHub Pages on a free account requires the repository to be
**public**, which means `data/alumni.json` (with names, emails,
education, employment history) becomes fetchable by anyone on the web.
Before flipping visibility, decide whether you're comfortable with that
or want to strip sensitive fields (e.g. `email`, `facebook`) from the
exported JSON first — see `scripts/export_sheet.py`.
