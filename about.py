import streamlit as st

from config import MAIN_APP_PAGE_CONFIG
from utils import ( 
                display_image, get_profile_pic_static_path,
                keep_last_entry_by_email, load_data,
                check_and_download_new_images
)

from st_pages import show_pages_from_config, add_page_title


show_pages_from_config()
st.set_page_config(**MAIN_APP_PAGE_CONFIG)
