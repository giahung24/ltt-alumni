import os
from pathlib import Path

STATIC_DIR = Path("./static")
IMG_DIR = STATIC_DIR / "img"

MAIN_APP_PAGE_CONFIG = {
    "page_title":"Kỷ yếu lớp Pháp - Chuyên Lý Tự Trọng Cần Thơ",
    "layout":"wide",
    "page_icon":"📒",
}

DATA_COLUMNS_NAME = ["timestamp", "fullname", "year", "picture_link", "education", "work", "email"]

THUMBNAIL_SIZE = (500, 500)