import re

import streamlit as st
import numpy as np
from pandas import DataFrame, to_datetime
from PIL import Image, ImageOps
from pathlib import Path
from oauth2client.service_account import ServiceAccountCredentials
from gspread_pandas import Spread
from pydrive2.auth import GoogleAuth
from pydrive2.drive import GoogleDrive
from unidecode import unidecode

from streamlit import secrets as st_secrets
from config import STATIC_DIR, DATA_COLUMNS_NAME, THUMBNAIL_SIZE, IMG_DIR


YEAR_RANGE_RE = re.compile(
    r"(?:19|20)\d{2} ?[\-\_] ?(?:(?:19|20)\d{2}|now|nay|pr[ée]sent|en cours|maintenant)"
)
NON_ALPHA_RE = re.compile(r"\W")


def emails_can_be_showed():
    if "show_email" in st.session_state:
        return st.session_state.show_email
    return False


# ---------------------------------------------------------------
# -- text processing
@st.cache_data(ttl=3600, show_spinner=False)
def make_anchor(string: str) -> str:
    string = unidecode(string.lower()).strip()
    string = NON_ALPHA_RE.sub(" ", string)
    return "-".join(string.split())


# ---------------------------------------------------------------
# -- IMAGE processing

def get_profile_pic_static_path(picture_id: str, ext: str = "*") -> str:
    """Get file in static/img with

    Parameters
    ----------
    picture_id : str
        file stem
    ext : str, optional
        file extension. If "*" given, get first file (from glob) with stem=picture_id

    Returns
    -------
    str
        image path
    """
    static_img_dir = f"./app/{STATIC_DIR.name}/img/"
    for path in IMG_DIR.glob(f"{picture_id}.{ext}"):
        return static_img_dir + path.name
    return static_img_dir + "default.jpg"


def display_image(static_path):
    st.markdown(
        f'<img src="{static_path}" height="333" style="border: 1px solid black">',
        unsafe_allow_html=True,
    )


def resize_image(img_path):
    image = Image.open(img_path)
    image.thumbnail(THUMBNAIL_SIZE)
    image = ImageOps.exif_transpose(image)
    image.save(img_path)


# ---------------------------------------------------------------
# dataframe processing

@st.cache_data(ttl=300, show_spinner=False)
def load_data(timestamp=None):
    # pass timestamp to by pass st cache mecanism
    return read_data_from_spreadsheet()


def check_and_download_new_images(data: DataFrame):
    """Check if there is any new photo_id in data sheet, then download it"""
    all_ids = [get_id_from_gg_link(link) for link in data["picture_link"].values]
    local_ids = [path.stem for path in (IMG_DIR).glob("*")]
    new_ids = list(set(all_ids).difference(local_ids))
    download_and_resize_images(new_ids)


def keep_last_entry_by_email(df):
    """If someone submits many entry with the same email, keep the latest"""
    return df.sort_values("timestamp").drop_duplicates(subset=["email"], keep="last")


def plot_email_count_by_year(data: DataFrame):
    email_count_by_year = data.groupby("year").agg({"email": "count"})
    st.bar_chart(email_count_by_year)


def make_clickable(url, name):
    return '<a href="{}" rel="noopener noreferrer" target="_self">{}</a>'.format(url, name)


# ------------------------------------------------
# google api

GG_CREDENTIALS = ServiceAccountCredentials.from_json_keyfile_dict(
    st_secrets["gg_service_account"],
    scopes=[
        "https://www.googleapis.com/auth/drive",
        "https://www.googleapis.com/auth/spreadsheets.readonly",
    ],
)


def get_id_from_gg_link(link):
    return link.split("?id=")[1]


def download_file_by_id(
    gg_drive_client: GoogleDrive, file_id: str, save_dir: Path, overwrite=False
) -> str:
    """Download file to save_dir.
    Return save path : save_dir/file_id.ext (ext get from gg drive metadata)
    """
    img_file = gg_drive_client.CreateFile({"id": file_id})
    img_file.FetchMetadata(fields="fullFileExtension")
    ext = img_file.metadata["fullFileExtension"]
    save_path = save_dir / f"{file_id}.{ext}"
    if not save_path.exists() or overwrite:
        print("Downloading", file_id)
        img_file.GetContentFile(save_path.as_posix())
    else:
        print("File exists. Skip downloading.")
    return save_path


def download_and_resize_images(file_ids: list):
    n_files = len(file_ids)
    if n_files > 0:
        gauth = GoogleAuth()
        gauth.credentials = GG_CREDENTIALS
        gauth.auth_method = "service"
        drive = GoogleDrive(gauth)
        count = 0
        print("Download photos from drive...")
        progress_text = f"Đang xử lý thông tin của {n_files} bạn mới..."
        progress_bar = st.progress(0, text=progress_text)
        for i, file_id in enumerate(file_ids):
            percent_completed = int(np.ceil(100 / n_files * i))
            progress_bar.progress(percent_completed, text=progress_text)
            save_path = download_file_by_id(drive, file_id, IMG_DIR)
            resize_image(save_path)
            count += 1
        progress_bar.empty()
        print(f"Downloaded {count} files. Done!")


def read_data_from_spreadsheet(sheet_id=None) -> DataFrame:
    if sheet_id is None:
        sheet_id = st_secrets["gg_data_sheet"]["id"]
    sheet = Spread(sheet_id, creds=GG_CREDENTIALS)
    df = sheet.sheet_to_df(index=0)
    df.columns = DATA_COLUMNS_NAME
    df["timestamp"] = to_datetime(df["timestamp"])
    df["fullname"] = df["fullname"].apply(lambda x: x.title())
    return df.fillna("").astype(str)
