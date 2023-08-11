import streamlit as st
from utils import (
        get_profile_pic_static_path, keep_last_entry_by_email, 
        load_data, display_image
)

# TODO :
# - Theme: change font
# Quote for main page



# -----------------------------
# ---------- MAIN APP ---------
st.set_page_config(page_title="Kỷ yếu lớp Pháp - Chuyên Lý Tự Trọng Cần Thơ")
st.title("Kỷ yếu Lớp Pháp ngữ")

st.info('-- some quote here --')

data = keep_last_entry_by_email(load_data())
all_years = sorted(data.year.unique())

with st.sidebar:
    years_selected = st.multiselect(
        "Năm tốt nghiệp",
        all_years,
        all_years
    )

    filtered_data = data.query("year.isin(@years_selected)").sort_values(by="year")
    if len(filtered_data) > 0:
        st.dataframe(filtered_data, 
                    hide_index=True, 
                    use_container_width=True,
                    column_order=["year","fullname"],
                    column_config={
                        "year": st.column_config.TextColumn("Năm tốt nghiệp"),
                        "fullname": st.column_config.TextColumn("Họ tên")}
        )
    else:
        st.warning("Chọn ít nhất một năm tốt nghiệp để hiển thị thông tin")


for i, entry in filtered_data.iterrows():
    pass  # show each "user card"
    # V0: markdown style
    st.markdown('---------------')
    
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

