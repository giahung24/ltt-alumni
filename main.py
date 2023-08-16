import streamlit as st

from config import MAIN_APP_PAGE_CONFIG
from utils import (check_and_download_new_images, display_image,
                   emails_can_be_showed, get_profile_pic_static_path,
                   keep_last_entry_by_email, load_data, make_anchor,
                   make_clickable)

st.set_page_config(**MAIN_APP_PAGE_CONFIG)

# --------- CSS ---------
st.markdown("""
        <style>
            .eczjsme4{
                padding-top: 1rem;
            }
            .ea3mdgi4{
                padding-top: 2em;
            }
            .tableFixHead          
            {   overflow: auto; 
                min-height: 100px;
                max-height: 500px; 
                border-collapse: collapse; 
                width: 100%;
            }

            table.dataframe{
                width:100%;
                border-collapse: collapse; 
                border: none !important;
                border-image-width: 0 !important;
            }
        </style>
        """, unsafe_allow_html=True)

# ----------------------------------------

# TODO :
# Quote for main page
# Theme: change font
# --- nice to have ---
# Better info show card (https://pypi.org/project/streamlit-image-select/)
# Format year range in description

# -----------------------------
# ---------- MAIN APP ---------
st.title("Kỷ yếu lớp Pháp ngữ", anchor=False)

st.info('-- some quote here --')

with st.spinner(text="Đang tải dữ liệu từ google cloud, bạn vui lòng chờ chút nhé..."):
    data = keep_last_entry_by_email(load_data())
    check_and_download_new_images(data)

all_years = sorted(data.year.unique())
all_years.insert(0, "Tất cả")

# --------- SIDEBAR ------------
with st.sidebar:
    year_selected = st.selectbox(
        "Lọc theo năm tốt nghiệp 🎓",
        all_years
    )
    filtered_data = data.sort_values(by="year").reset_index(drop=True)
    if year_selected != "Tất cả":
        filtered_data = data.query("year==@year_selected").reset_index(drop=True)
    filtered_data.index = filtered_data.index + 1

    filtered_data["anchor"] = filtered_data["year"].astype(str) + "-" + filtered_data["fullname"]
    filtered_data["anchor"] = "./#" + filtered_data["anchor"].apply(make_anchor)
    filtered_data['anchor'] = filtered_data.apply(lambda x: make_clickable(x['anchor'], x['fullname']), axis=1)
        
    html_table = (filtered_data.reset_index()[["index","year","anchor"]]
                    .rename(columns={"index":"#️⃣","year":"🎓","anchor":"🧑‍🎓"})
                    .style.hide(axis="index").to_html(index=False, justify="left", border=0)
                )
    html_div = f"""<div class="tableFixHead">
        {html_table}
    """
    st.markdown(html_div, unsafe_allow_html=True)

    # st.dataframe(filtered_data.style,
    #              hide_index=False,
    #              use_container_width=True,
    #              column_order=["year","fullname"],
    #              column_config={
    #                 "year": st.column_config.TextColumn("🎓"),
    #                 "fullname": st.column_config.TextColumn("🧑‍🎓"),
    #                 }
    # )

    # ----------------------
    # info section
    st.markdown("-----------------")
    st.markdown("### Hướng dẫn 🌟")
    st.success("Thêm hoặc cập nhật thông tin tại [đây](https://forms.gle/9dLQzcNBy9NQWMEW6)", icon="🔗")
    st.warning("Để cập nhật thông tin đã có, bạn dùng form trên và điền đúng email đã dùng của lần trước, hệ thống chỉ hiển thị bản ghi sau cùng của mỗi email.")
    if st.button("Hiển thị email của mọi người", use_container_width=True):
        st.session_state.show_email = True
    st.success("Sau khi thêm hoặc cập nhật, nếu chưa thấy thông tin được hiển thị trên trang này, bạn có thể bấm nút dưới đây để tải lại dữ liệu")
    if st.button("Cập nhật dữ liệu", use_container_width=True, type="primary"):
        st.cache_data.clear()  
        st.experimental_rerun()


# -------- MAIN CONTAINER -----------

for i, entry in filtered_data.iterrows():
    # show each "user card"
    # V0: markdown style
    st.markdown('---------------')
    
    year = int(entry["year"])
    st.subheader(f"[{year-3} - {year}]")

    fullname = " ".join(entry["fullname"].replace(","," ").split())
    st.header(fullname, anchor=make_anchor(f"{year}-{fullname}"))
    
    if emails_can_be_showed():
        email = entry["email"]
        st.markdown(email)
    
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


