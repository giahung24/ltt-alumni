from pathlib import Path
import streamlit as st
import pandas as pd

STATIC_DIR = Path("./static")

# ---------------------------------------------------------------
# -- IMAGE processing
def get_profile_pic_static_path(picture_id, ext="jpg"):
    static_img_dir = "./app/static/img/"
    photo_path = STATIC_DIR/"img"/f"{picture_id}.{ext}"
    if photo_path.exists():
        return static_img_dir + f"{picture_id}.{ext}"
    return static_img_dir + "default.jpg"


def display_image(static_path):
    st.markdown(
        f'<img src="{static_path}" height="333" style="border: 1px solid orange">',
        unsafe_allow_html=True,
    )

# ---------------------------------------------------------------
# dataframe processing
st.cache_data()
def load_data():
    path = "data.xlsx"
    data = pd.read_excel(path)
    data.columns = ["timestamp", "fullname", "email", "year", "picture_link", "education", "work"]
    return data.astype(str)


def keep_last_entry_by_email(df):
    """If someone submits many entry with the same email, keep the latest"""
    return df.sort_values("timestamp").drop_duplicates(subset=["email"], keep="last")


def plot_email_count_by_year(data:pd.DataFrame):
    email_count_by_year = data.groupby("year").agg({"email":"count"})
    st.bar_chart(email_count_by_year)
