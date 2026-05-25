# TODO

Follow-up actions for the HTML/JS clone and the weekly refresh workflow.

## Required before the weekly refresh will work

- [ ] **Create the `STREAMLIT_SECRETS_TOML` repository secret.**
  Settings → Secrets and variables → Actions → New repository secret.
  - Name: `STREAMLIT_SECRETS_TOML`
  - Value: the entire content of the local `.streamlit/secrets.toml`
    file, including the `[gg_service_account]` table (with the multi-line
    `private_key`) and `[gg_data_sheet]` with `id`.

- [ ] **Merge `.github/workflows/refresh-data.yml` into the default branch.**
  GitHub only fires scheduled workflows from the default branch. Until
  this file lands on `main`, the Sunday 01:00 GMT+7 cron will not run.
  Either:
  - open a PR from `claude/project-html-js-clone-CCeZa` to `main`, or
  - cherry-pick the workflow commit (`ff8624e`) to `main` on its own.

- [ ] **Smoke-test the workflow manually.**
  After the two steps above: Actions → "Refresh alumni data" → Run
  workflow. Verify it produces a commit to `data/alumni.json` (or logs
  "No changes" if the sheet hasn't moved).

## Data and deployment

- [ ] **Replace the sample `data/alumni.json` with a real export.**
  The committed file currently has 7 dummy rows. Either wait for the
  first scheduled refresh, run the workflow manually, or run
  `python scripts/export_sheet.py` locally and commit the result.

- [ ] **(Optional) Enable GitHub Pages** to serve the static site.
  Settings → Pages → Source: the branch you want to publish (e.g.
  `main`), folder `/ (root)`. The site will live at
  `https://giahung24.github.io/ltt-alumni/`.

## Nice-to-haves (not blocking)

- [ ] Add a "last refreshed" timestamp to the page, sourced from
  `data/alumni.json`'s `exported_at` field.
- [ ] Decide whether to keep the Streamlit app and the HTML/JS clone in
  the same repo long-term, or split the static site into its own repo.
