import streamlit as st

from config import MAIN_APP_PAGE_CONFIG
from utils import ( 
                display_image, get_profile_pic_static_path,
                keep_last_entry_by_email, load_data,
                check_and_download_new_images, make_anchor
)
from st_pages import show_pages_from_config, add_page_title


show_pages_from_config()
st.set_page_config(**MAIN_APP_PAGE_CONFIG)

# TODO :
# Quote for main page
# Theme: change font
# Better info show card (https://pypi.org/project/streamlit-image-select/)
# Format year range in description

# -----------------------------
# ---------- MAIN APP ---------
st.title("Kỷ yếu lớp Pháp ngữ", anchor=False)

st.info('-- some quote here --')

with st.spinner(text="Kiểm tra dữ liệu từ google form, bạn vui lòng chờ chút nhé..."):
    data = keep_last_entry_by_email(load_data())
    check_and_download_new_images(data)

all_years = sorted(data.year.unique())
all_years.insert(0, "Tất cả")

# --------- SIDEBAR ------------
with st.sidebar:
    year_selected = st.selectbox(
        "Năm tốt nghiệp",
        all_years
    )
    filtered_data = data.sort_values(by="year").reset_index(drop=True)
    if year_selected != "Tất cả":
        filtered_data = data.query("year==@year_selected").reset_index(drop=True)
    filtered_data.index = filtered_data.index + 1
    st.dataframe(filtered_data, 
                hide_index=False, 
                use_container_width=True,
                column_order=["year","fullname"],
                column_config={
                    "year": st.column_config.TextColumn("Năm tốt nghiệp"),
                    "fullname": st.column_config.TextColumn("Họ tên")}
    )
# -------- MAIN CONTAINER -----------

for i, entry in filtered_data.iterrows():
    # show each "user card"
    # V0: markdown style
    st.markdown('---------------')
    
    year = int(entry["year"])
    st.subheader(f"[{year-3} - {year}]")

    fullname = " ".join(entry["fullname"].replace(","," ").split())
    st.header(fullname.title(), anchor=make_anchor(f"{year}-{fullname}"))

    # email = entry["email"]
    # st.subheader(email)
    
    pic_id = entry["picture_link"].split("?id=")[-1]
    pic_path = get_profile_pic_static_path(pic_id)
    display_image(pic_path)
    
    education = entry["education"]
    st.subheader("Ngành học")
    st.text(education)

    work = entry["work"]
    if work:
        st.subheader("Công việc")
        st.text(work)

    last_update = entry["timestamp"].split()[0]
    st.caption(f"Cập nhật lần cuối: "+ last_update)

