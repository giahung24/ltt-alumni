import streamlit as st

from config import MAIN_APP_PAGE_CONFIG
from utils import ( 
                display_image, get_profile_pic_static_path,
                keep_last_entry_by_email, load_data,
                check_and_download_new_images
)

from st_pages import show_pages_from_config, add_page_title


# add_page_title()

show_pages_from_config()

# TODO :
# Theme: change font
# Better info show card
# Format year range in description
# Quote for main page

st.set_page_config(**MAIN_APP_PAGE_CONFIG)

# -----------------------------
# ---------- MAIN APP ---------
st.title("Kỷ yếu Lớp Pháp ngữ")

st.info('-- some quote here --')

with st.spinner(text="Kiểm tra dữ liệu từ google form, bạn vui lòng chờ chút nhé..."):
    data = keep_last_entry_by_email(load_data())
    check_and_download_new_images(data)

all_years = sorted(data.year.unique())

# --------- SIDEBAR ------------
with st.sidebar:
    years_selected = st.multiselect(
        "Năm tốt nghiệp",
        all_years,
        all_years
    )

    filtered_data = data.query("year.isin(@years_selected)").sort_values(by="year").reset_index(drop=True)
    filtered_data.index = filtered_data.index + 1
    if len(filtered_data) > 0:
        st.dataframe(filtered_data, 
                    hide_index=False, 
                    use_container_width=True,
                    column_order=["year","fullname"],
                    column_config={
                        "year": st.column_config.TextColumn("Năm tốt nghiệp"),
                        "fullname": st.column_config.TextColumn("Họ tên")}
        )
    else:
        st.warning("Chọn ít nhất một năm tốt nghiệp để hiển thị thông tin")

# -------- MAIN CONTAINER -----------

for i, entry in filtered_data.iterrows():
    # show each "user card"
    # V0: markdown style
    st.markdown('---------------')
    
    year = int(entry["year"])
    st.subheader(f"[{year-3} - {year}]")

    fullname = " ".join(entry["fullname"].replace(","," ").split())
    st.header(fullname)

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

